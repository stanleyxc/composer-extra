<?php

namespace Stanleyxc\ComposerExtra;

use Composer\Script\Event;
use Composer\Installer\PackageEvent;

class Script
{

    public static $composer_root    = null;                    //composer's vendor dir.
    public static $local_repo       = null;                    //local repository holding installed packages.
    public static $extra            = null;                    //extra property block in composer.json.
    public static $installation_map = array();                 //package name & installation path map.
    public static $package_map      = array();                 //package name & package inteface map - used to manipulate installed.json file.


    public static function usage() {
      print "Usage:\n\n";

      print "\tcomposer <script name> [-- [--dry-run] [--verbose] [--no-deregister] ]<package/path>\n";
      print "\tcomposer <script name> all\n";
      print "\tcomposer <script name> stanleyxc/composer-extra\n";
      print "You can run script with optional flag.\n";
      print "\tcomposer <script name> -- --dry-run stanleyxc/composer-extra\n";
      print "\tcomposer <script name> -- --verbose twig/twig\n";


    }

    public static function main(Event $event) {
        $options = array('verbose' => false, 'dry-run' => false, 'deregister' => true);           //default options

        $composer = $event->getComposer();
        Script::$composer_root =  $composer->getConfig()->get('vendor-dir');
        Script::$extra = $composer->getPackage()->getExtra();

        if(!Script::$extra) {
            print "Error:  no extra parameter specified in composer.json";
            return false;
        }
        if(!Script::$extra['deployment'] ) {
            print "Error: no deployment info specified in composer.json extra.";
            return false;
        }

        Script::$local_repo =  $composer->getRepositoryManager()->getLocalRepository();
        $installed_packages = Script::$local_repo->getPackages();
        if(empty($installed_packages)) {
            print "No packages installed via composer!";
            return false;
        }
        $installman = $composer->getInstallationManager();
        foreach($installed_packages as $p) {
            $pkg_name = $p->getName();
            Script::$installation_map[$pkg_name] = $installman->getInstallPath($p);
            Script::$package_map[$pkg_name] = $p;

        }

        $args = $event->getArguments();

        if(empty($args)) {
            Script::usage();
            return false;
        }

        for($i = 1; $i <= 3; $i++) {
            if($args[0] === '--verbose' || $args[0] === '--dry-run' || $args[0] === '--no-deregister') {
                $v = array_shift($args);
                $options['verbose'] = ($v === '--verbose') ? true : $options['verbose'];
                $options['dry-run'] = ($v === '--dry-run') ? true : $options['dry-run'];
                $options['deregister'] = ($v === '--no-deregister') ? false : $options['deregister'];
            }
        }
        $options['verbose'] = ($options['dry-run']) ? true : $options['verbose'];           //dry run implies verbose on.
        $prefix =  ($options['dry-run']) ? 'Dry run - ' :  '';

        if(strtolower($args[0]) === 'all') {
            Script::clean_all($options);
        } else {
            foreach($args as $package) {
                if($options['verbose']) {  print  "$prefix Cleaning composer package '$package'.\n"; }
                if(!Script::$extra['deployment'][$package]) {
                    if($options['verbose']) {
                        print "$prefix Warning: $package skipped.  Reason: deployment info for $package not found!\n";
                    }
                    continue;
                }
                Script::clean_one($package, Script::$extra['deployment'][$package], $options);
                print "$package cleaned!\n";
            }
        }
    }
    public static function clean_one($package, $deployment_data, $options = array()) {

        //find packages installation dir

        if(!isset( Script::$installation_map[$package])) {
            print "Error: $package not installed via composer!\n";
            return false;
        }
        $installation_path = Script::$installation_map[$package];

        if($options['verbose']) {
            print "$package installed at: " . $installation_path. "\n";
        }
        try{
            $wanted_list = array();
            //build wanted list.
            foreach($deployment_data as $pattern) {
                $resolved_path = realpath("$installation_path/$pattern");
                if($resolved_path === false) {             //pattern is wildcard or to doesn't actually exist...try to resolve the parent directory.
                    $parts = pathinfo($pattern);
                    $resolved_path = realpath($installation_path  . '/' . $parts['dirname']);
                    if($resolved_path === false) {        //still couldn't resolve path, skip this pattern.
                        continue;
                    }
                    $resolved_path = $resolved_path . '/' . $parts['basename'];
                }
                $wanted_list [] = $resolved_path;
            }
            $cleaner = new Cleaner(Script::$composer_root, $options);
            $cleaner->brute_force_clean($wanted_list, $installation_path );
            if(   $options['dry-run'] === false
              &&  $options['deregister'] === true
            ) {
              //manipulate composer's installed.json file:
              //  These two lines will remove the packge information of "cleaned" from local installed repository.
              //  Afterward, composer will see the 'cleaned' package as not been installed, i.e composer show -i will not list the 'cleaned' package.
              //  This way, running compose update will re-install the 'cleaned' package(s) afresh.

              //If you don't want this behavir, pass the 'no-degreister' flag.
              //A note of caution though: without removing the package information from installed.json, composer will never know that some component of installed package have been deleted  and
              //will never try to re-install the package unless newer version is detected, or package's installation diretory is completly removed.
                Script::$local_repo->removePackage(Script::$package_map[$package]);
                Script::$local_repo->write();
            }
        }catch (Exception $e ) {
            print $e->getTraceAsString();
        }

    }
    public static function clean_all($options = array())
    {
        foreach(Script::$extra['deployment'] as $package => $deployment_data) {
            Script::clean_one($package, $deployment_data, $options);
        }

    }

}
