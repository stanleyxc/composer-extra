<?php

namespace Stanleyxc\ComposerExtra;

class Cleaner
{

    private $composer_root = null;
    private $options = array('verbose' => false, 'dry-run' => false, 'quiet' => false);           //default options
    private $io = null;



    public function __construct($composer_root_path, $options = array(), $cio = null) {
        $this->composer_root = $composer_root_path;
        $this->cio = $cio;
        $this->options = array_merge($this->options, $options);
        if($this->options['dry-run']) {
            $this->options['verbose'] = true;
        }
    }
    // place holder for fnmatch right now until coming up with a better pattern matcher.
    private function _pmatch($pattern, $value) {
        return fnmatch($pattern, $value, FNM_NOESCAPE|FNM_PATHNAME );
    }

    public function brute_force_clean($wanted, $dir) {
        if(strlen(trim($dir)) === 0 || empty($wanted)) return false;

        sort($wanted, SORT_STRING );
        $dir_path = realpath($dir);
        $items = array_diff(scandir($dir_path), array('.','..'));

        foreach ($items as $item) {
            $f = "$dir_path/$item";
            $keep_item = false;
            if(is_dir($f)) {                // find items in the wanted list belonging to this directory. */
                $matched = array();
                foreach($wanted as $pattern) {
                    if($this->_pmatch($pattern, $f)) {             //keep the entire directory.
                        $keep_item = true; break;
                    }
                    if($f === substr($pattern, 0, strlen($f))){
                        $matched [] = $pattern;
                    }
                }
                if($keep_item === false) {
                    //we don't need to keep the entire directory...but we do have items in the wanted list belong in this directory, descend into it.  Otherwise remove this directory.
                    (!empty($matched) ) ? $this->brute_force_clean($matched, $f) : $this->safe_rm($f, $dir_path);
                }
            } else {                          //non-directory item, treat it as regular file subject to file pattern matching.
                foreach($wanted as $pattern) {
                    if($this->_pmatch($pattern, $f)) {
                        $keep_item = true;
                        break;
                    }
                }
                if($keep_item === false) {
                    $this->safe_rm($f, $dir_path);
                }
            }
        }
    }

    protected function safe_rm($path, $jail) {
        if($this->composer_root === null) {
            throw new UnexpectedValueException("Composer (vendor) root path is blank!");
            return false;
        }
        if(strlen(trim($path)) === 0 || strlen(trim($jail)) === 0 ) return false;

        $path = realpath($path);
        $threshold = realpath($jail);
        if($path === '/' ) {
          throw new UnexpectedValueException("Let's be reasonable.  You don't really want to delete root directory, do you?");
          return false;
        }
        if ($this->composer_root !== substr($path, 0, strlen($this->composer_root))){
            throw new UnexpectedValueException("Remove '$path' blocked! Reason: remove files outside of Composer is not allowed!");
            return false;
        }
        if ($threshold !== substr($path, 0, strlen($threshold))){
            throw new UnexpectedValueException("Remove '$path' blocked! Reason: remove files outside of package's install directory is not allowed!");
            return false;
        }
        $runmode =  ($this->options['dry-run']) ? 'Dry run - ' :  '';
        if(!is_dir($path)) {
            $this->_verbose("$runmode removing file $path");
            if($this->options['dry-run'] === false){
                return unlink($path);
            }
            return true;
        }
        $items = array_diff(scandir($path), array('.','..'));
        foreach ($items as $item) {
            $this->safe_rm("$path/$item", $jail);
        }
        $this->_verbose("$runmode removing directory $path");
        if($this->options['dry-run'] === false){
            return rmdir($path);
        }

        return true;
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
