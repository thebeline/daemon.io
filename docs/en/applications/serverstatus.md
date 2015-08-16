### Serverstatus # ServerStatus #> ServerStatus {tpl-git PHPDaemon/Applications/ServerStatus.php}

```Php:p
namespace PHPDaemon\Applications;
class ServerStatus;
```

This application provides information about the status of phpDaemon over HTTP, similar to a console command `phpd fullstatus`.

#### use # Use

You must add in `conf/phpd.conf`:

```
ServerStatus {
    enable 1;
}
HTTP {
    enable 1;
    privileged;
}
```

Also in the `conf/AppResolver.php` in method `getRequestRoute()` add the condition to run the method `beginRequest()` in Annex ServerStatus. For example, to get information about phpDaemon at http://<host>/ServerStatus/:

```Php
/**
 * Routes incoming request to related application. Method is for overloading.   
 * @param Object Request.
 * @param Object AppInstance of Upstream.
 * @return String Application's name.
 */
public function getRequestRoute($req, $upstream) {
    if (preg_match ('~ ^/(ServerStatus|Example)/~', $req->attrs->server['DOCUMENT_URI'], $m)) {
        return $m[1];
    }
}
```

Example Response:

```
Uptime: 1 day. 11 hour. 33 min. 51 sec.
State of workers:
        Total: 4
        Idle: 4
        Busy: 0
        Shutdown: 20
        Pre-init: 0
        Wait-init: 0
        Init: 0
```

#### note # Note

If you use the --logworkersetstatus, the line is:

 - 1 - idle
 - 2 - busy
 - 3 - shutdown
 - 4 - pre-init
 - 5 - wait-init
 - 6 - init
