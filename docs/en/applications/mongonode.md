### Mongonode # MongoNode #> MongoNode {tpl-git PHPDaemon/Examples/MongoNode.php}

```Php:p
namespace PHPDaemon\Examples;
class MongoNode;
```

This application provides a node replication MongoDB. This makes it possible to install arbitrary hooks for add/change/delete objects.

#### Requirements # Requirements

Required module installed pecl/mongo and the included phpdaemon/MongoCllient.

#### Use # Use

MongoNode When enabled, it immediately gets the new changes in the database.

Default:

If the object has the property "_key" serialized its value is sent to the key Memcache under that name, which is set within the meaning of _key. When an object is removed from the MongoDB, it is removed from Memcache.

If the object has the property "_ev", its value is sent to the RTEP-event under the name, which is set within the meaning of _ev.
