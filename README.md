# composer-extra
A simple deployment cleaner for composer packages.


A 'make clean' like functionality to clean up composer's packages to minimize deployment code size footprint.  
Leveraging composer's plug-in API, script functionality.
Introduces a custom sub-block (named deployment) in composer.json schema's extra property.

### Warning:
This is pre-production release.  I've only used and test in Linux enviroment.  Nor have I tested this in composer global install settings.

I strongly recommand you use this tool in your dev environment extensively before trying it on production.  And since this tool
make changes to your filesystem (it uses php fnmatch with unlink and rmdir), I can't caution enough --- make sure you have backups in place 
before running this tool


###Composer Install:
```
composer require stanleyxc/composer-extra
```
###Usage:
Using composer plug-in  
```
composer deploy-clean twig/twig phpmailer/phpmailer  
```
The above command will clean composer package twig and phpmailer assuming there are extra/deployment information in 
your project's root composer.json file.  For example, below is extra/deployment specifies the files/directories I want 
for my deployment (your's likely will be different)
```  
{  
    "extra":  
    {  
    	"deployment":  
      {  
        "phpmailer/phpmailer" 	: ["*.php", "extras", "LICENSE", "VERSION"],  
        "twig/twig"             : ["LICENSE", "lib", "doc", "ext"]  
	    }  
    }  
}  
```

Deployment parameter block contains one array list per package specifying the directories and files you want to keep for deployment. 
Everything else not listed will be removed.


Using composer script functionality.    
In order to use deployment cleaner in script mode, one more parameter blocks are needed in composer.json: script name and hook defintion.

```
{  
    "extra":  
    {  
    	"deployment":  
      {  
        "phpmailer/phpmailer" 	: ["*.php", "extras", "LICENSE", "VERSION"],  
        "twig/twig"             : ["LICENSE", "lib", "doc", "ext"]  
	    }  
    },
    "scripts":
    {
       "script:deploy" :
       [
          "Stanleyxc\\ComposerExtra\\Script::main"
       ]
    }
}    
```    
then run 

```
composer script:deploy twig/twig phpmailer/phpmailer  
```

---
You can also customize the command in script mode to your liking, eg. rename script:deploy to clean then
```
composer clean twig/twig
```

