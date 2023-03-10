<?php
/**
 * Quinn Interactive Silverstripe deployer recipe
 * Version: 1.0.0-rc1
 */

namespace Deployer;

use Dotenv\Dotenv;
use Symfony\Component\Console\Helper\Table;

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

    // get the remote environment
    $remote_hostname = get('hostname');
    if ('live' == get('environment_name')) {
        $vars_text = run('env | grep SS_');
        $dotenv_remote = Dotenv::parse($vars_text);
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
    runLocally(sprintf('mysqldump --add-drop-database --add-locks --disable-keys --extended-insert --single-transaction --quick %s > %s', $db, $sql_file_local));
    runLocally(sprintf('rsync -zavP %s %s@%s:%s', $sql_file_local, get('remote_user'), $remote_hostname, $sql_file_remote));

    // Remotely load the DB
    run(sprintf('< %s mysql -v %s', $sql_file_remote, $dotenv_remote['SS_DATABASE_NAME']));

    // Clean up temporary DB files
    unlink($sql_file_local);
    run("rm ${sql_file_remote}");

    // Tar up the assets remotely
    $shared_public_dir = sprintf('%s/%s', get('deploy_path'), 'shared/public');
    $remote_tar_file = run('mktemp');
    cd($shared_public_dir);
    run(sprintf('sudo tar -cf %s %s', $remote_tar_file, 'assets'));

    // tar up the local assets
    $local_tar_file = tempnam(sys_get_temp_dir(), 'assetstar');
    runLocally(sprintf("cd public && tar -cf %s %s", $local_tar_file, 'assets'));

    // Upload the assets tar file
    runLocally(sprintf('rsync %s -zavP %s@%s:%s', $local_tar_file, get('remote_user'), $remote_hostname, $remote_tar_file));

    // Replace the remote assets
    $zfs = get('zfs');
    $assets_dir = sprintf('/var/silverstripe/%s/shared/public/assets', get('environment_name'));

    if ($zfs) {
        /// destroy and re-create the zfs dataset
        $zfs_dataset = sprintf('zroot/var/silverstripe/%s/shared/public/assets', get('environment_name'));
        run(sprintf('sudo zfs destroy %s', $zfs_dataset));
        run(sprintf('sudo zfs create %s', $zfs_dataset));
    } else {
        /// delete & re-create the UFS directory for assets
        run(sprintf('sudo rm -rf %s', $assets_dir));
        run(sprintf('sudo mkdir -p %s', $assets_dir));
    }
    run(sprintf('sudo chown -R %s:%s %s', get('remote_user'), get('http_user'), $assets_dir));
    run(sprintf('sudo chmod -R g+w %s', $assets_dir));

    /// Extract the tar file (DO NOT use sudo here!)
    cd($shared_public_dir);
    run(sprintf('tar -xf %s', $remote_tar_file));
    run(sprintf('sudo chown -R %s:%s %s', get('http_user'), get('http_user'), $assets_dir));

    // clean up temporary tar files
    runLocally("rm ${local_tar_file}");
    run("rm ${remote_tar_file}");
})->desc('Overwrite remote DB and assets with local files');

task('download', function () {
    global $dotenv_local;

    // get the remote environment
    $remote_hostname = get('hostname');
    if ('live' == get('environment_name')) {
        $vars_text = run('env | grep SS_');
        $dotenv_remote = Dotenv::parse($vars_text);
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
    $sql_file_local = tempnam(sys_get_temp_dir(), $db);
    run(sprintf('mysqldump --add-drop-database --add-locks --disable-keys --extended-insert --single-transaction --quick %s > %s', $db, $sql_file_remote));
    runLocally(sprintf('rsync -zavP %s@%s:%s %s', get('remote_user'), $remote_hostname, $sql_file_remote, $sql_file_local));

    // Locally load the DB
    runLocally(sprintf('< %s mysql %s', $sql_file_local, $dotenv_local['SS_DATABASE_NAME']));

    // Clean up temporary DB files
    unlink($sql_file_local);
    run("rm ${sql_file_remote}");

    // Tar up the assets remotely
    $shared_public_dir = sprintf('%s/%s', get('deploy_path'), 'shared/public');
    $remote_tar_file = run('mktemp');
    cd($shared_public_dir);
    run(sprintf('sudo tar -cf %s %s', $remote_tar_file, 'assets'));

    // tar up the local assets
    $local_tar_file = tempnam(sys_get_temp_dir(), 'assetstar');
    runLocally(sprintf("cd public && tar -cf %s %s", $local_tar_file, 'assets'));

    // Download the remote assets tar file
    runLocally(sprintf('rsync -zavP %s@%s:%s %s', get('remote_user'), $remote_hostname, $remote_tar_file, $local_tar_file));

    // Replace the local assets
    runLocally('rm -rf public/assets');
    runLocally(sprintf('cd public && tar -xf %s', $local_tar_file));

    // clean up temporary tar files
    runLocally("rm ${local_tar_file}");
    run("rm ${remote_tar_file}");
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
        throw error("Refusing to deploy: local and remote ${branch} branches are different.");
    }
});

// ROBOTS.TXT
task('silverstripe:robots', function () {
    if (preg_match('/^(dev|demo)/', get('environment_name'))) {
        run('cp {{release_path}}/robots/dev/robots.txt {{release_path}}/public');
    } else {
        run('cp {{release_path}}/robots/live/robots.txt {{release_path}}/public');
    }
})->desc('Copy robots.txt into public');

// THEME
task('silverstripe:theme', function () {
    if (get('themeless')) {
        return;
    }
    if (preg_match('/^(live|demo)/', get('environment_name'))) {
        run('cd {{release_path}}/themes/{{theme_name}} && yarn && yarn production');
    } else {
        run('cd {{release_path}}/themes/{{theme_name}} && yarn && yarn dev');
    }
})->desc('Build the theme with yarn');

// VENDOR-EXPOSE
task('composer:vendor-expose', function () {
    run('cd {{release_path}} && {{bin/composer}} vendor-expose');
})->desc('composer vendor-expose');

// NGINX
task('nginx:reload', function () {
    run(sprintf('[ -x %s ] && sudo service nginx reload || exit 0', get('nginx_path', '/usr/local/sbin/nginx')));
})->desc('Reload the nginx service')->oncePerNode();

// dev/build OVERRIDES to add 'sudo -Eu www'
task('silverstripe:build', function () {
    return run('sudo -Eu {{http_user}} {{bin/php}} {{release_path}}/{{silverstripe_cli_script}} /dev/build');
})->desc('Run sudo -Eu {{http_user}} /dev/build');

task('silverstripe:buildflush', function () {
    return run('sudo -Eu {{http_user}} {{bin/php}} {{release_path}}/{{silverstripe_cli_script}} /dev/build flush=all');
})->desc('Run sudo -Eu {{http_user}} /dev/build?flush=all');

desc('Deploys your project');
task('deploy', [
    'git:remote-update',
    'git:check',
    'deploy:prepare',
    'deploy:vendors',
    'silverstripe:theme',
    'composer:vendor-expose',
    'silverstripe:robots',
    'silverstripe:buildflush',
    'deploy:publish',
]);

task('qi:releases', function () {
    cd('{{deploy_path}}');

    $releasesLog = get('releases_log');
    $currentRelease = basename(run('readlink {{current_path}}'));
    $releasesList = get('releases_list');

    $table = [];
    $tz = !empty(getenv('TIMEZONE')) ? getenv('TIMEZONE') : date_default_timezone_get();

    foreach ($releasesLog as &$metainfo) {
        $date = \DateTime::createFromFormat(\DateTime::ATOM, $metainfo['created_at']);
        $date->setTimezone(new \DateTimeZone($tz));
        $status = $release = $metainfo['release_name'];
        if (in_array($release, $releasesList, true)) {
            if (test("[ -f releases/$release/BAD_RELEASE ]")) {
                $status = "<error>$release</error> (bad)";
            } elseif (test("[ -f releases/$release/DIRTY_RELEASE ]")) {
                $status = "<error>$release</error> (dirty)";
            } else {
                $status = "<info>$release</info>";
            }
        }
        if ($release === $currentRelease) {
            $status .= ' (current)';
        }
        try {
            $rev = escapeshellarg(run("cat releases/$release/REVISION"));
            $revision = runLocally("git describe $rev");
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
        ->setHeaders(["Date ($tz)", 'Release', 'Author', 'Target', 'Version'])
        ->setRows($table)
        ->render();
})->desc('Show release table with git descriptions');

// sequence modifications
before('upload', 'checkzfs');
after('deploy:symlink', 'nginx:reload');
after('deploy:failed', 'deploy:unlock');
after('rollback', 'silverstripe:buildflush');
