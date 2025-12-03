<?php
/**
 * Quinn Interactive Silverstripe deployer recipe
 * Version: 2.2.2
 */

namespace Deployer;

use Dotenv\Dotenv;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputOption;

require_once 'vendor/deployer/deployer/recipe/common.php';
add('recipes', ['silverstripe']);

// Config
set('allow_anonymous_stats', false);
set('shell', 'bash -s');
set('silverstripe_cli_script', 'vendor/silverstripe/framework/cli-script.php');
set('shared_assets', function () {
    if (test('[ -d {{release_or_current_path}}/public ]') || test('[ -d {{deploy_path}}/shared/public ]')) {
        return 'public/assets';
    }
    return 'assets';
});
set('shared_dirs', ['{{shared_assets}}']);
set('writable_dirs', ['{{shared_assets}}']);

set('dotenv_dir', '{{deploy_path}}/releases');
set('current_branch', exec('git branch --show-current'));
set('theme_name', 'main');
set('themeless', false);

// support for graphql disk artifacts
set('cleanup_use_sudo', true);
set('writable_use_sudo', true);
set('writable_mode', 'chown');
set('writable_dirs', [
    '.graphql-generated',
    'public/_graphql',
]);

/**
 * Return mysql CLI options for DB connection if we can't use unix_socket.
 * Detect this by the presence of SS_DATABASE_PASSWORD in the environment.
 * Return the empty string if SS_DATABASE_PASSWORD is absent.
 *
 * @param array $env
 * @return string
 */
function dbConnectionOptions(array $env)
{
    $db_connection_options = '';
    $password = $env['SS_DATABASE_PASSWORD'] ?? null;
    if ($password !== null) {
        $db_connection_options .= sprintf('-p"%s" ', $password);
        if (isset($env['SS_DATABASE_PORT'])) {
            $db_connection_options .= sprintf('-P "%s" ', $env['SS_DATABASE_PORT']);
        }
        if (isset($env['SS_DATABASE_USERNAME'])) {
            $db_connection_options .= sprintf('-u "%s" ', $env['SS_DATABASE_USERNAME']);
        }
        if (isset($env['SS_DATABASE_SERVER'])) {
            $db_connection_options .= sprintf('-h "%s" ', $env['SS_DATABASE_SERVER']);
        }
    }
    return $db_connection_options;
}

// Tasks
task('info', function () {
    $info = [
        'deploy_path'      => get('deploy_path'),
        'environment_name' => get('environment_name'),
        'hostname'         => get('hostname'),
        'dotenv_dir'       => get('dotenv_dir'),
        'remote_user'      => get('remote_user'),
        'http_user'        => get('http_user'),
        'user'             => get('user'),
    ];
    print_r($info);
})->hidden();

// upload/download (move data & assets up or down, overwriting the target)
task('checkzfs', function () {
    if (get('zfs') === null) {
        throw error('Must set zfs to true or false to upload');
    }
});
task('preventlive', function () {
    if (get('environment_name') == 'live') {
        throw error('The requested operation is not allowed on live.');
    }
});
task('upload', function () {
    global $dotenv_local;

    if (get('environment_name') == 'live' && empty($_SERVER['UNSAFE_UPLOAD'])) {
        throw error('Upload does not work in production (set UNSAFE_UPLOAD in shell environment to override).');
    }

    // get the remote environment; easy on live; must parse .env on non-production
    $remote_hostname = get('hostname');
    if ('live' == get('environment_name')) {
        $dotenv_remote = remoteEnv();
    } else {
        $dotenv_dir = get('dotenv_dir');
        $dotenv_tmp = tempnam(sys_get_temp_dir(), 'dotenv');
        runLocally(sprintf('scp %s@%s:%s/.env %s', get('remote_user'), $remote_hostname, $dotenv_dir, $dotenv_tmp));
        $dotenv_remote = Dotenv::createArrayBacked(sys_get_temp_dir(), basename($dotenv_tmp))->load();
        unlink($dotenv_tmp);
    }

    // Dump the DB locally & transfer to remote
    $sql_file_remote = run('mktemp');
    $db = $dotenv_local['SS_DATABASE_NAME'];
    $sql_file_local = tempnam(sys_get_temp_dir(), $db);
    info('Dumping DB locally');
    runLocally(sprintf('mysqldump --add-drop-database --add-locks --disable-keys --extended-insert --single-transaction --quick %s > %s', $db, $sql_file_local));
    info('Uploading DB');
    runLocally(sprintf('rsync -zavP %s %s@%s:%s', $sql_file_local, get('remote_user'), $remote_hostname, $sql_file_remote));

    // Drop & re-create the remote database to prevent artefacts
    $db_connection_options = dbConnectionOptions($dotenv_remote);
    info('Purging DB remotely');
    run(sprintf('mysqladmin %s drop -f %s create %s', $db_connection_options, $dotenv_remote['SS_DATABASE_NAME'], $dotenv_remote['SS_DATABASE_NAME']));

    // Remotely load the DB
    info('Loading DB remotely');
    run(sprintf('< %s mysql %s %s', $sql_file_remote, $db_connection_options, $dotenv_remote['SS_DATABASE_NAME']));

    // clean up temporary SQL files
    info('Cleaning up temporary SQL files');
    unlink($sql_file_local);
    run("rm {$sql_file_remote}");

    // Tar up the assets remotely
    info('Building remote assets archive');
    $shared_public_dir = sprintf('%s/%s', get('deploy_path'), 'shared/public');
    $remote_tar_file = run('mktemp');
    cd($shared_public_dir);
    run(sprintf('doas tar -cf %s %s', $remote_tar_file, 'assets'));

    // tar up the local assets
    info('Building local assets archive');
    $local_tar_file = tempnam(sys_get_temp_dir(), 'assetstar');
    runLocally('[ -d public/assets ] || mkdir public/assets');
    runLocally(sprintf("cd public && tar -cf %s %s", $local_tar_file, 'assets'));

    // Upload the assets tar file
    info('Uploading assets archive');
    runLocally(sprintf('rsync %s -zavP %s@%s:%s', $local_tar_file, get('remote_user'), $remote_hostname, $remote_tar_file));

    // Replace the remote assets
    info('Deleting remote assets');
    $zfs = get('zfs');
    $assets_dir = sprintf('/var/silverstripe/%s/shared/public/assets', get('environment_name'));

    if ($zfs) {
        /// destroy and re-create the zfs dataset
        $zfs_dataset = sprintf('zroot/var/silverstripe/%s/shared/public/assets', get('environment_name'));
        run(sprintf('doas zfs destroy %s', $zfs_dataset));
        run(sprintf('doas zfs create %s', $zfs_dataset));
    } else {
        /// delete & re-create the UFS directory for assets
        run(sprintf('doas rm -rf %s', $assets_dir));
        run(sprintf('doas mkdir -p %s', $assets_dir));
    }
    run(sprintf('doas chown -R %s:%s %s', get('remote_user'), get('http_user'), $assets_dir));
    run(sprintf('doas chmod -R g+w %s', $assets_dir));

    /// Extract the tar file (DO NOT use doas here!)
    info('Extracting assets from archive on remote');
    cd($shared_public_dir);
    run(sprintf('tar -xf %s', $remote_tar_file));
    run(sprintf('doas chown -R %s:%s %s', get('http_user'), get('http_user'), $assets_dir));

    // clean up temporary tar files
    info('Cleaning up temporary tar files');
    runLocally("rm {$local_tar_file}");
    run("rm {$remote_tar_file}");
    info('Upload done!');
})->desc('Overwrite remote DB and assets with local files');

task('download', function () {
    global $dotenv_local;
    $abend = false;

    // get the remote environment; easy on live; must parse .env on non-production
    $remote_hostname = get('hostname');
    if ('live' == get('environment_name')) {
        $dotenv_remote = remoteEnv();
    } else {
        $dotenv_dir = get('dotenv_dir');
        $dotenv_tmp = tempnam(sys_get_temp_dir(), 'dotenv');
        runLocally(sprintf('scp %s@%s:%s/.env %s', get('remote_user'), $remote_hostname, $dotenv_dir, $dotenv_tmp));
        $dotenv_remote = Dotenv::createArrayBacked(sys_get_temp_dir(), basename($dotenv_tmp))->load();
        unlink($dotenv_tmp);
    }

    // Dump the DB remotely & download it
    $sql_file_remote = run('mktemp');
    $db = $dotenv_remote['SS_DATABASE_NAME'];
    $db_connection_options = dbConnectionOptions($dotenv_remote);
    $sql_file_local = tempnam(sys_get_temp_dir(), $db);
    try {
        info('Retrieving DB');
        run(sprintf('mysqldump %s --add-drop-database --add-locks --disable-keys --extended-insert --single-transaction --quick %s > %s', $db_connection_options, $db, $sql_file_remote));
        runLocally(sprintf('rsync -zavP %s@%s:%s %s', get('remote_user'), $remote_hostname, $sql_file_remote, $sql_file_local));

        // Drop & re-create the local database to prevent artefacts
        info('Purging DB locally');
        runLocally(sprintf('mysqladmin drop -f %s create %s', $dotenv_local['SS_DATABASE_NAME'], $dotenv_local['SS_DATABASE_NAME']));

        // Locally load the DB
        info('Loading DB locally');
        runLocally(sprintf('< %s mysql %s', $sql_file_local, $dotenv_local['SS_DATABASE_NAME']));
    } catch (\Exception $e) {
        warning($e->getMessage());
        $abend = true;
    } finally {
        // clean up temporary SQL files
        info('Cleaning up temporary SQL files');
        if (file_exists($sql_file_local)) {
            unlink($sql_file_local);
        }
        run("rm -f {$sql_file_remote}");
        if ($abend) {
            warning('Exiting because of previous exception.');
            exit(1);
        }
    }

    // Transfer the assets
    $abend = false;
    try {
        // Tar up the assets remotely
        info('Building remote assets archive');
        $shared_public_dir = sprintf('%s/%s', get('deploy_path'), 'shared/public');
        $remote_tar_file = run('mktemp');
        cd($shared_public_dir);
        run(sprintf('doas tar -cf %s %s', $remote_tar_file, 'assets'));

        // tar up the local assets
        info('Building local assets archive');
        $local_tar_file = tempnam(sys_get_temp_dir(), 'assetstar');
        runLocally('[ -d public/assets ] || mkdir public/assets');
        runLocally(sprintf("cd public && tar -cf %s %s", $local_tar_file, 'assets'));

        // Download the remote assets tar file
        info('Downloading assets archive via rsync');
        runLocally(sprintf('rsync -zavP %s@%s:%s %s', get('remote_user'), $remote_hostname, $remote_tar_file, $local_tar_file));

        // Replace the local assets
        info('Extracting assets locally');
        runLocally('command -v trash && trash public/assets || exit 0');
        runLocally('command -v trash || mv public/assets public/Xassets');
        runLocally(sprintf('cd public && tar -xf %s', $local_tar_file));
    } catch (\Exception $e) {
        warning($e->getMessage());
        $abend = true;
    } finally {
        // clean up temporary tar files
        info('Cleaning up temporary tar files');
        if (file_exists($local_tar_file)) {
            unlink($local_tar_file);
        }
        run("rm -f {$remote_tar_file}");
        runLocally('[ -d public/Xassets ] && rm -rf public/Xassets || exit 0');
        if ($abend) {
            warning('Exiting because of previous exception.');
            exit(1);
        }
    }
    info('Download done');
})->desc('Overwrite local DB and assets with remote files');

// git
task('git:remote-update', function () {
    runLocally('git remote update');
});

task('git:check', function () {
    $branch = exec('git symbolic-ref --short -q HEAD');
    $result_code = -1;
    system(sprintf('git diff --quiet %s origin/%s', $branch, $branch), $result_code);
    if ($result_code !== 0) {
        throw error("Refusing to deploy: local and remote {$branch} branches are different.");
    }
});

// ROBOTS.TXT
task('silverstripe:robots', function () {
    if (preg_match('/^(dev|demo)/', get('environment_name'))) {
        foreach ([
            '{{release_path}}/robots/{{environment_name}}/robots.txt',
            '{{release_path}}/robots/dev/robots.txt',
            '{{release_path}}/robots/robots.txt'
            ] as $robots_path) {
            if (test("[ -f $robots_path ]")) {
                run("cp {$robots_path} {{release_path}}/public");
                break;
            }
        }
    } else {
        $robots_path = '{{release_path}}/robots/live/robots.txt';
        if (test("[ -f $robots_path ]")) {
            run("cp $robots_path {{release_path}}/public");
        }
    }
})->desc('Copy robots.txt into public');

// THEME
task('silverstripe:theme', function () {
    if (get('themeless')) {
        return;
    }
    $theme_path = get('theme_path');
    if (!$theme_path) {
        $theme_path = '{{release_path}}/themes/{{theme_name}}';
    } else {
        $theme_path = sprintf('{{release_path}}/%s', $theme_path);
    }
    if (preg_match('/^(live|demo)/', get('environment_name'))) {
        $build_type = 'production';
    } else {
        $build_type = 'dev';
    }
    run(sprintf('cd %s && yarn && yarn %s', $theme_path, $build_type));
})->desc('Build the theme with yarn');

// VENDOR-EXPOSE
task('composer:vendor-expose', function () {
    run('cd {{release_path}} && {{bin/composer}} vendor-expose');
})->desc('composer vendor-expose');

// NGINX
task('nginx:reload', function () {
    run(sprintf('[ -x %s ] && doas service nginx reload || exit 0', get('nginx_path', '/usr/local/sbin/nginx')));
})->desc('Reload the nginx service')->oncePerNode();

// substitute for silverstripe:build and silverstripe:buildflush (because dev/build always flushes anyway)
task('silverstripe:devbuild', function () {
    return run('doas -u {{http_user}} {{bin/php}} {{release_or_current_path}}/{{silverstripe_cli_script}} /dev/build');
})->desc('Run doas -u {{http_user}} /dev/build');

desc('Deploys your project');
task('deploy', [
    'git:remote-update',
    'git:check',
    'deploy:prepare',
    'deploy:vendors',
    'silverstripe:theme',
    'composer:vendor-expose',
    'silverstripe:robots',
    'silverstripe:devbuild',
    'deploy:publish',
]);

option('all', 'a', InputOption::VALUE_NONE, 'List all releases, even the deleted ones.', null);
task('qi:releases', function () {
    cd('{{deploy_path}}');

    $showing_all = input()->getOption('all');
    $releasesLog = get('releases_log');
    $currentRelease = basename(run('readlink {{current_path}}'));
    $releasesList = get('releases_list');

    $table = [];
    $tz = !empty(getenv('TIMEZONE')) ? getenv('TIMEZONE') : date_default_timezone_get();

    foreach ($releasesLog as $metainfo) {
        $date = \DateTime::createFromFormat(\DateTime::ATOM, $metainfo['created_at']);
        $date->setTimezone(new \DateTimeZone($tz));
        $status = $release = $metainfo['release_name'];
        $release_exists = in_array($release, $releasesList);
        if (!$release_exists && !$showing_all) {
            continue;
        }
        if ($release_exists) {
            if (test("[ -f releases/{$release}/BAD_RELEASE ]")) {
                $status = "<error>{$release}</error> (bad)";
            } elseif (test("[ -f releases/{$release}/DIRTY_RELEASE ]")) {
                $status = "<error>{$release}</error> (dirty)";
            } else {
                $status = "<info>{$release}</info>";
            }
        }
        if ($release === $currentRelease) {
            $status .= ' (current)';
        }
        try {
            if ($release_exists) {
                $rev = escapeshellarg(run("cat releases/{$release}/REVISION"));
                $revision = runLocally("git describe {$rev}");
            } else {
                $revision = 'n/a';
            }
        } catch (\Throwable $e) {
            $revision = 'unknown';
        }
        $table[] = [
            $date->format("Y-m-d H:i:s"),
            $status,
            $metainfo['user'],
            $metainfo['target'],
            $revision,
        ];
    }

    (new Table(output()))
        ->setHeaderTitle(currentHost()->getAlias())
        ->setHeaders(["Date ({$tz})", 'Release', 'Author', 'Target', 'Version'])
        ->setRows($table)
        ->render();
})->desc('Show release table with git descriptions');

// sequence modifications
before('upload', 'checkzfs');
after('deploy:symlink', 'nginx:reload');
after('deploy:failed', 'deploy:unlock');
after('rollback', 'silverstripe:devbuild');
