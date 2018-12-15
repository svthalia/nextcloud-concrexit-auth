Concrexit user sync and authentication
============================
**Authenticate user login against a concrexit instance.**

This plugin sync users and group from concrexit to local tables inside NextCloud.

Add the following to your `config.php`:
```
    'concrexit' => array(
        'host' => 'https://myconcrexit.example',
        'secret' => 'supersecretapitoken'
    ),
```

And add the plugin code to your `custom_apps` folder. The plugin should be in a folder called `concrexitauth`.