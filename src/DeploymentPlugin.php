<?php

namespace Stanleyxc\ComposerExtra;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Command\BaseCommand;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;


class DeploymentPlugin  implements PluginInterface, Capable
{
    private $composer;
    private $io;
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        if($io->isVerbose()){
            $io->write("Plugin " . __NAMESPACE__ . " activated.");
        }
    }
    public function getCapabilities()
    {
       return array(
           'Composer\Plugin\Capability\CommandProvider' => 'Stanleyxc\ComposerExtra\DeploymentCommandProvider',
       );
    }

}


class DeploymentCommandProvider implements CommandProvider
{
    public function getCommands()
    {
        return array(new DeploymentCommand());
    }
}

class DeploymentCommand extends BaseCommand
{
    private $composer;
    private $io;

    private $cleaner = null;

    protected $cio = null;          // command execution io using SymfonyStyle.
    protected $package_map = array();
    protected $deployment_rules = array();
    private $options = array('verbose' => false, 'dry-run' => false, 'quiet' => false, 'deregister' => true);           //default options


    protected function configure()
    {
        $this
            ->setName('deploy-clean')
            ->setDescription('A package cleaner for  deployment.')
            ->setHelp("Like make clean.  A  simple package cleaner targeting  Composer's  filesystem.  Useful for deployment.")
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Outputs the operations but will not execute anything (implicitly enables --verbose).')
            ->addOption('no-deregister', null, InputOption::VALUE_NONE, "Do not remove package information from composer's installed.json.  Default is to remove them so composer update will re-install the package afresh.")
            ->addArgument('package', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'packages to clean (separated by spaces)');

    }

    protected function initialize(InputInterface $in, OutputInterface $out) {
        $this->composer = $this->getComposer();
        $this->io = $this->getIO();
        $this->cio = new SymfonyStyle($in, $out);


        $extra_params = $this->composer->getPackage()->getExtra();
        if(!$extra_params) {
          $this->_warn("nothing to do - extra parameter block missing in composer.json");
          return false;
        }
        if(!$extra_params['deployment']) {
          $this->_warn('nothing to do - deployment info not found in extra parameter block.');
          return false;
        }
        $this->deployment_rules = $extra_params['deployment'];
        $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();
        foreach($packages as $p) {
            $this->package_map[$p->getName()] = array(
                'pkg_interface' => $p,
                'installation_path' => $this->composer->getInstallationManager()->getInstallPath($p)
            );
        }
        $this->cleaner = new Cleaner($this->composer->getConfig()->get('vendor-dir'), $this->options, $this->cio);       // instantiate cleaner object.
        return true;

    }

    protected function execute(InputInterface $in, OutputInterface $out)
    {
        if($in->getOption('no-deregister')) { $this->options['deregister'] = false; }
        if($in->getOption('quiet')) { $this->options['quiet'] = true; }
        if($in->getOption('verbose')) { $this->options['verbose'] = true; }
        if($in->getOption('dry-run')) {
            $this->options['dry-run'] = $this->options['verbose'] = true;
        }

        if(false === $this->initialize($in, $out)) return false;

        if(!$this->options['quiet']) {
            (null !== $this->cio)   ? $this->cio->title("Running ". __NAMESPACE__ ." plugin")
                                    : $out->writeln(array(
                                        "Running ". __NAMESPACE__ ." plugin",
                                        '===========================================',
                                        ''
                                    ));
        }
        $packages = $in->getArgument('package');
        $pkg_count = count($packages);
        //Do we really want to clean all specified packages when no argument is present?!
        if(     $pkg_count == 1
            &&  strtoupper($packages[0]) === 'ALL'
        ) {
            $this->_info("clean all packages installed by Composer at:" . $this->composer->getConfig()->get('vendor-dir'));
            $this->clean_all();

        } else {
            $this->_info("clean $pkg_count specified pacckage(s) installed by Composer.");
            if(!$this->options['quiet'] && $this->cio !== null)  {
                $this->cio->progressStart($pkg_count);                  //show progress
            }
            $runmode =  ($this->options['dry-run']) ? 'Dry run - ' :  '';
            foreach($packages as $package) {
                if(!isset($this->deployment_rules[$package])) {
                    $this->_warn("$runmode $package skipped because deployment information not found!");
                    if(!$this->options['quiet'] && $this->cio !== null)  {
                        $this->cio->progressAdvance(1);
                    }
                    continue;
                }
                $this->clean_one($package);
                if(!$this->options['quiet'] && $this->cio !== null)  {
                    $this->cio->progressAdvance(1);
                }
            }
        }

        if(!$this->options['quiet']) {
            (null !== $this->cio) ? $this->cio->text('') : $out->writeln('');
        }
    }
    public  function clean_all()
    {
        foreach($this->deployment_rules as $package => $deployment_data) {
            $this->clean_one($package);
        }
    }
    public function clean_one($package) {

        if(!isset($this->package_map[$package])) {
            $this->_error("$package not installed via composer!");
            return false;
        }
        $installation_path = $this->package_map[$package]['installation_path'];

        $this->_verbose("cleaning $package installed at: $installation_path.");

        try{
            $wanted_list = array();                        //build wanted list.
            foreach($this->deployment_rules[$package] as $pattern) {
                $resolved_path = realpath("$installation_path/$pattern");
                if($resolved_path === false) {             //up a directory for wildcard pattern or files that don't actually exist.
                    $parts = pathinfo($pattern);
                    $resolved_path = realpath($installation_path  . '/' . $parts['dirname']);
                    if($resolved_path === false) {        //still couldn't resolve path, skip this pattern.
                        $this->_verbose("couldn't resolve $pattern to actionable path. Skipped.");
                        continue;
                    }
                    $resolved_path = $resolved_path . '/' . $parts['basename'];
                }
                $wanted_list [] = $resolved_path;
            }
            $this->cleaner->brute_force_clean($wanted_list, $installation_path );
            if($this->options['dry-run'] === false
              && $this->options['deregister'] === true
            ) {
                $this->_verbose("unregistering $package from Composer's installed.json file.");
                //manipulate composer's installed.json file:
                //  These two lines will remove the packge information of "cleaned" from local installed repository.
                //  Afterward, composer will see the 'cleaned' package as not been installed, i.e composer show -i will not list the 'cleaned' package.
                //  This way, running compose update will re-install the 'cleaned' package(s) afresh.

                //If you don't want this behavir, pass the 'no-degreister' flag.
                //A note of caution though: without removing the package information from installed.json, composer will never know that some component of installed package have been deleted  and
                //will never try to re-install the package unless newer version is detected, or package's installation diretory is completly removed.
                $this->composer->getRepositoryManager()->getLocalRepository()->removePackage($this->package_map[$package]['pkg_interface']);
                $this->composer->getRepositoryManager()->getLocalRepository()->write();
            }
        }catch (Exception $e ) {
            print $e->getTraceAsString();
        }

    }


    private function _info($s) {
        if($this->options['quiet']) return false;
        (null === $this->cio) ? print "$s\n" : $this->cio->note($s);
        return true;
    }
    private function _verbose($s) {
        if(!$this->options['verbose']) return true;
        (null === $this->cio) ? print "$s\n" : $this->cio->text($s);
        return true;
    }
    private function _warn($s) {
        if($this->options['quiet']) return true;
        (null === $this->cio) ? print "$s\n" : $this->cio->warning($s);
        return true;
    }
    private function _error($s) {
        (null === $this->cio) ? print "$s\n" : $this->cio->error($s);
        return true;
    }


}
