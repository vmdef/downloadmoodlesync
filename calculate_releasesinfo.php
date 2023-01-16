<?php

/**
 * Script in charge of generating the json file
 * to be used to send packages info to main donwload site
 */

// We are one valid entry point to all the updates stuff
define('MOODLE_INTERNAL', true); // version.php loading requires this

/**
 * Given one version.php file, extract as many information as we can from it
 *
 * @param string $file full path to the file to parse
 * @return array with all the information gathered
 */
function updates_parse_versionfile($file) {
    $results = array();
    // Require it
    require($file);
    $results['version'] = $version;
    $results['release'] = $release;
    $results['branch'] = isset($branch) ? $branch : null;
    $results['maturity'] = isset($maturity) ? $maturity : MATURITY_STABLE;

    // Need the branch in X.Y format.
    if ($results['branch']) {
        $results['branch'] = substr($results['branch'], 0, 1) . '.' . substr($results['branch'], 1);
    } else {
        // Have to extract it from release information.
        if (preg_match('/^[0-9]+\.[0-9]+/', $results['release'], $match)) {
            $results['branch'] = $match[0];
        } else {
            return array(); // Return empty array.
        }
    }

    // Now calculate the release "numbers" (taking out the Build part)
    if (preg_match('/^[^ ]+/', $results['release'], $match)) {
        $results['releasenumbers'] = $match[0];
    } else {
        return array(); // Return empty array.
    }

    // Finally, based on releasenumbers, determine the release type (release/nonrelease)
    // If the releasenumbers has only numbers and dots... it's a release, else it's not.
    if (preg_match('/[^0-9.]/', $results['releasenumbers'])) {
        $results['isrelease'] = false;
    } else {
        $results['isrelease'] = true;
    }

    return $results;
}

/**
 * Given one release info file, extract as many information as we can from it
 *
 * @param string $file full path to the file to parse
 * @return array with all the information gathered
 */
function parse_info_file($file) {
    $info = [];
    if (file_exists($file)) {
        require($file);

        $info['version'] = $version;
        $info['branch'] = $branch;
        $info['githash'] = $githash;
    }
    return $info;
}

/**
 * Get package info.
 *
 * Moodle code is packaged on a ZIP or TGZ file.
 *
 * @param string $file Full path to the package.
 * @return array with all the information gathered
 */
function get_package_info($file) {
    $info['size'] = @filesize($file);
    $filemd5 = $file . '.md5';
    $info['md5'] = file_exists($filemd5);
    $filesha256 = $file . '.sha256';
    $info['sha256'] = file_exists($filesha256);

    return $info;
}

/** @var string $cfg_packagesjsonfile Path to the json file generated with the packages info. */
$cfg_packagesjsonfile = '/tmp/packages.json';

/** @var string $cfg_releasesurl URL of the download site. */
$cfg_releasesurl = 'https://download.moodle.org';

/**
 * Directories where the source code for a given major release is available
 * It supports XY and X.Y.Z templating, so it will work automatically for any new XY or X.Y.Z
 * found. They are processed in order.
 *
 * @var array
 */
$cfg_vcsdirs = array(
    'default' => array(
            'dir' => '/var/www/vhosts/download.moodle.org/data/stableXY/moodle',
            'packagedir' => '/var/www/vhosts/download.moodle.org/html-extra/stableXY',
            'downloadweekly' => 'https://download.moodle.org/download.php/direct/stableXY/moodle-latest-XY.zip',
            'downloadrelease' => 'https://download.moodle.org/download.php/direct/stableXY/moodle-X.Y.Z.zip'),
    'master'  => array(
            'dir' => '/var/www/vhosts/download.moodle.org/data/head/moodle',
            'packagedir' => '/var/www/vhosts/download.moodle.org/html-extra/moodle',
            'downloadweekly' => 'https://download.moodle.org/download.php/direct/moodle/moodle-latest.zip',
            'downloadrelease' => null)
    );

/** @var string $cfg_mackpackagesdir Path to macOS packages folder. */
$cfg_mackpackagesdir = __DIR__ . '/../macosx/';

/** @var string $cfg_macpackagesinfofile Path to the PHP file with the info about the macOS packages. */
$cfg_macpackagesinfofile = $cfg_mackpackagesdir . 'maccfg.php';

// This script only can run from cli
if (php_sapi_name() !== 'cli') {
    error_log(__FILE__ . ': This script only can run from CLI');
    exit(1);
}

$results = array('timestamp' => time(), 'releases' => array());
$releases = array();

// Verify that the $cfg_packagesjsonfile dir exists and it's writeable
if (!is_writeable(dirname($cfg_packagesjsonfile))) {
    error_log('Directory ' . dirname($cfg_packagesjsonfile) . ' must exist and be writeable');
    exit(1);
}

// Verify that the $cfg_packagesjsonfile file, if exists, is writeable
if (file_exists($cfg_packagesjsonfile) and !is_writeable($cfg_packagesjsonfile)) {
    error_log('File ' . $cfg_packagesjsonfile . ' must be writeable');
    exit(1);
}

// Iterate over all the directories specified in $cfg_vcsdirs
// looking for their version.php files and parsing them to
// extract the $version, $release, $maturity, $url and $download
foreach ($cfg_vcsdirs as $key => $template) {
    // If not set $template['dir'] skip
    if (empty($template['dir'])) {
        error_log('Skipping rule: ' . $key . '. Missing dir information!');
        continue;
    }
    $templatedir = $template['dir'];
    $templatepackagedir = $template['packagedir'];
    $templatedownloadweekly = !empty($template['downloadweekly']) ? $template['downloadweekly'] : '';
    $templatedownloadrelease = !empty($template['downloadrelease']) ? $template['downloadrelease'] : '';
    error_log('Processing rule: ' . $key . ' => ' . $templatedir);
    // Split templatedir into base and suffix
    $xybase = $templatedir;
    $xysuffix = '';
    if (strpos($templatedir, 'XY') !== false) {
        $xysuffix = preg_replace('/^.*XY(.*)$/', '\\1', $templatedir);
        $xybase   = substr($templatedir, 0, - strlen($xysuffix));
    }
    $parentdir = dirname($xybase);

    // We support the XY templating, meaning numerical 2-4 digits.
    $regexp = str_replace('XY', '\\d{2,4}', basename($xybase)) . '$';
    if (!is_dir($parentdir)) {
        error_log('Skipping incorrect directory ' . $parentdir);
        continue;
    }

    if ($dh = opendir($parentdir)) {
        while (($file = readdir($dh)) !== false) {
            // Skip any dot file
            if (preg_match('/^\./', $file)) {
                continue;
            }
            if (!preg_match('/' . $regexp . '/', $file)) {
                error_log('Skipping non-matching directory ' . $parentdir . '/' . $file);
                continue;
            }

            if (!is_dir($parentdir . '/' . $file)) {
                error_log('Skipping incorrect directory ' . $parentdir . '/' . $file);
                continue;
            }

            if (!is_readable($parentdir . '/' . $file . $xysuffix . '/version.php')) {
                error_log('Skipping directory ' . $parentdir . '/' . $file . $xysuffix . '. No version.php file available');
                continue;
            }

            // Arrived here, we have one version.php file to process
            error_log('Found candidate directory ' . $parentdir . '/' . $file . $xysuffix);
            $info = updates_parse_versionfile($parentdir . '/' . $file . $xysuffix . '/version.php');

            if (empty($info)) {
                error_log('Cannot extract information from ' . $parentdir . '/' . $file . $xysuffix . '/version.php');
                continue;
            }

            // We have all the information, let's prepare the release information.
            $branch = str_replace('.', '', $info['branch']); // Branch without dots, for XY replacement.
            // Use the correct download template.
            if ($info['isrelease']) {
                $templatedownload = $templatedownloadrelease;
            } else {
                $templatedownload = $templatedownloadweekly;
            }
            // Verify we have a template.
            if (empty($templatedownload)) {
                error_log('Template for ' . $info['releasenumbers'] . ' not found');
                continue;
            }
            // Apply the XY replacements.
            $templatedownload = str_replace('XY', $branch, $templatedownload);
            // Apply the X.Y.Z replacements.
            $templatedownload = str_replace('X.Y.Z', $info['releasenumbers'], $templatedownload);

            // Get the *release_info files
            $packagedir = str_replace('XY', $branch, $templatepackagedir);
            if (file_exists($packagedir . '/norelease_index_info.php')) {
                $noreleaseinfo = parse_info_file($packagedir . '/norelease_index_info.php');
                $filename = 'moodle-latest';
                if ($noreleaseinfo['branch'] != "master") {
                    $filename = $filename . '-'. str_replace('.', '', $info['branch']);
                }
                // Zip file info.
                $zipfile = $packagedir . '/' . $filename . '.zip';
                // Weekly date.
                $noreleaseinfo['date'] = @filemtime($zipfile);
                $noreleaseinfo['zip'] = get_package_info($zipfile);

                // Tgz file info.
                $tgzfile = $packagedir . '/' . $filename . '.tgz';
                $noreleaseinfo['tgz'] = get_package_info($tgzfile);
            }

            if (file_exists($packagedir . '/release_index_info.php')) {
                $releaseinfo = parse_info_file($packagedir . '/release_index_info.php');
                $filename = 'moodle-' . $releaseinfo['version'];

                // Zip file info.
                $zipfile = $packagedir . '/' . $filename . '.zip';
                // Release date.
                $releaseinfo['date'] = @filemtime($zipfile);
                $releaseinfo['zip'] = get_package_info($zipfile);

                // Tgz file info.
                $tgzfile = $packagedir . '/' . $filename . '.tgz';
                $releaseinfo['tgz'] = get_package_info($tgzfile);
            }

            // Windows installer file info.
            $windowsfile = dirname($packagedir) . '/windows/' . 'MoodleWindowsInstaller-latest';
            if ($noreleaseinfo['branch'] != "master") {
                $windowsfile .= '-' . $branch;
            }
            $windowsfile .=  '.zip';
            $windowsfileinfo['size'] = @filesize($windowsfile);

            // Date from zip file creation.
            $info['date'] = @filemtime($zipfile);

            // Done, complete the array.
            $info['url'] = $cfg_releasesurl;
            $info['download'] = $templatedownload;
            if (isset($noreleaseinfo)) {
                $info['norelease_index_info'] = $noreleaseinfo;
            }
            if (isset($releaseinfo)) {
                $info['release_index_info'] = $releaseinfo;
            }

            $info['windows'] = $windowsfileinfo;

            // Clean stuff not to be published.
            unset($info['releasenumbers']);
            unset($info['isrelease']);
            unset($releaseinfo);
            unset($noreleaseinfo);
            // And add it to the results.
            $releases[] = $info;
        }
        closedir($dh);
    }
}

// Add the found releases to the results
$results['releases'] = $releases;

// Verify that the $maccfg.php file exists
if (file_exists($cfg_macpackagesinfofile) and !is_readable($cfg_macpackagesinfofile)) {
    error_log('File ' . $cfg_macpackagesinfofile . ' must be readable');
    exit(1);
}

require $cfg_macpackagesinfofile;

$macosxpackages = [];
foreach($versions as $version) {
    $macosxpackage['name'] = $version['name'];
    $macosxpackage['git'] = $version['git'];
    $macosxpackage['desc'] = $version['desc'];
    $macosxpackage['mamp'] = $version['mamp'];
    $macosxpackage['name'] = $version['name'];
    $macosxpackage['age'] = $version['age'];
    $macosxpackage['size'] = @filesize($cfg_mackpackagesdir . $version['mamp']);

    $macosxpackages[] = $macosxpackage;
}

// Add the found macOS packages to the results
$results['macospackages'] = $macosxpackages;

// Write down the new releaseseinfo file
file_put_contents($cfg_packagesjsonfile, json_encode($results));
