# NTISyncBundle


### Installation

1. Install the bundle using composer:

    ```
    $ composer require nti/sync-bundle "dev-master"
    ```

2. Add the bundle configuration to the AppKernel

    ```
    public function registerBundles()
    {
        $bundles = array(
            ...
            new NTI\SyncBundle\NTISyncBundle(),
            ...
        );
    }
    ```

3. Update the database schema

    ```
    $ php app/console doctrine:schema:update
    ```

4.  Add the routes to your `routing.yml`

    ```
    ...
    nti_sync:
        resource: "@NTISyncBundle/Resources/config/routing.yml"
    ```

## Requirements

Below are a list of things that need to be considered in order to implement this bundle:

1. Any Entity that needs to be taken into account during the synchronization process must have the `@NTI\SyncEntity` annotation at the class level.
2. `ManyToOne` relationships that should alter the last synchronization timestamp of their parents should use the annotation `@NTI\SyncParent(getter="[Getter Name]")` (see example below for more information).
3. Entities to be synced must have a repository implementing the `SyncRepositoryInterface` (see below for more information).
4. The mapping `SyncMapping` needs to be configured foreach entity as it is the list used as reference for the lookup.
5. The `SyncState` should be created for each mapping. This can be done with this query after creating all the `SyncMapping`:
    ```
    `INSERT INTO nti_sync_state(mapping_id, timestamp) SELECT id, 0  FROM sync_nti_mapping;`
6. If the entity is going to be synched FROM the client, then a service must be defined in the `SyncMapping` database entry. Also, this method needs to implement the interface `SyncServiceInterface`. 

## Tracking Changes

The way that the bundle tracks changes in the synchronization is as follows:

1. The bundle has a `DoctrineEventListener` listening to the `onFlush` event.
2. Once the event is fired, the bundle will grab every entitty that has the `@NTI\SyncEntity` annotation.
3. If the entity has a `SyncMapping` defined, the system will update the `last_timestamp` field of this mapping to the current `time()`.
4. If the entity has a method called `setLastTimestamp()` it will be called with the `time()` as a parameter and the changes will be recomputed or computed.
5. All the properties of the entity will be examined in search for a property that contains the annotation `@NTI\SyncParent(getter="[Getter Name]")`.
   If found, the getter will be called, if the result is an object that also has the `@NTI\SyncEntity`, it will be processed again starting from point #3. This process occurrs recursively.
  
## Configuration

Below is the default configuration for the bundle. In case you need to modify the defaults this would go in your `config.yml`:

```yaml
nti_sync:
  deletes:
      
    # Identifier to use when an item gets deleted. This would go in your `deletes` section as shown below
    identifier_getter: "getId"
        

```

## Class Examples

    ```
    <?php
    
    ...
    use NTI\Annotations as NTI;    
    
    /**
     * ...
     * @NTI\SyncEntity()
     */
    public class Product {
        ...        
        
        /**
         * @ORM\Column(name="last_timestamp", type="bigint", options={"default": 0})
         */
        private $lastTimestamp;
        
        ...
        
        /**
         * Set lastTimestamp
         * @param $lastTimestamp
         * @return Company
         */
        public function setLastTimestamp($lastTimestamp)
        {
            $this->lastTimestamp = $lastTimestamp;    
            return $this;
        }
    
        /**
         * Get lastTimestamp
         * @return integer
         */
        public function getLastTimestamp()
        {
            return $this->lastTimestamp;
        }

    }    
    
An example of a class using a `ManyToOne` where the child also needs the parent's `last_timestamp` to be updated can be defined as:

    ```
    <?php
    
    ...
    use NTI\Annotations as NTI;    
    
    /**
     * ...
     * @NTI\SyncEntity()
     */
    public class ProductChild {
        ...        
                
        /**
         * @NTI\SyncParent(getter="getProduct")
         * @ORM\ManyToOne(targetEntity="AppBundle\Entity\Product\Product")
         */
        private $product;
        
        ...
            
        /**
         * Get Product
         * @return Product
         */
        public function getProduct()
        {
            return $this->product;
        }
    }          
  
Below is the general process that the bundles goes through to keep track of the synchronization state:

![Synchronization Process - Server](/Images/SynchronizationProcess-Server.PNG?raw=true "Synchronization State Process on the Server")

Below is the general process that occurs when a client asks for the changes after a specific timestamp:

![Synchronization Process - Client](/Images/SynchronizationProcess-Client.PNG?raw=true "Synchronization Process on the Client")

## Implementation (Pull)

The idea behind the synchronization process is that every object that is going to be synchronized should implement the `SyncRepositoryInterface` in its repository.

```
/**
 * Interface SyncRepositoryInterface
 * @package NTI\SyncBundle\Interfaces
 */
interface SyncRepositoryInterface {
    /**
     * This function should return a plain array containing the results to be sent to the client
     * when a sync is requested. The container is also passed as a parameter in order to give additional
     * flexibility to the repository when making decision on what to show to the client. For example, if the user
     * making the request only has access to a portion of the data, this can be handled via the container in this method
     * of the repository.
     *
     * Note 1: If the `updatedOn`  of a child entity is the one that is affected and not the parent, you may have to take that
     *         into account when doing your queries so that the updated information shows up in the results if desired when doing
     *         the comparison with the timestamp
     * 
     *         For example:
     *         
     *              $qb -> ...
     *              $qb -> leftJoin('a.b', 'b')
     *              $qb -> andWhere($qb->expr()->orX(
     *                  $qb->expr()->gte('a.lastTimestamp', $date),
     *                  $qb->expr()->gte('b.lastTimestamp', $date)
     *              ))
     *              ...
     *              
     *         This way if the only way of syncronizing B is through A, next time A gets synched B changes will be reflected. 
     * 
     * The resulting structure should be the following:
     * 
     * array(
     *      "data" => (array of objects),
     *      SyncState::REAL_LAST_TIMESTAMP => (last updated_on date from the array of objects),
     * )
     *     
     *
     * @param $timestamp
     * @param ContainerInterface $container
     * @param array $serializationGroups
     * @return mixed
     */
    public function findFromTimestamp($timestamp, ContainerInterface $container, $serializationGroups = array());
```

Besides implementing the interface, in the database `nti_sync_mapping` the mapping for each class that is going to be synchronized should be configured along with a name.


First, the idea is to get a summary of the changes and mappings from the server:

```
GET /nti/sync/summary
```

To which the server will respond with the following structure:

```
[
    {
        "id": 1,
        "mapping": {
            "id": 1,
            "name": "Product",
            "class": "AppBundle\\Entity\\Product\\Product",
            "sync_service": "AppBundle\\Service\\Product\\ProductService"
        },
        "timestamp": 1515076764
    },
    ...
]
```

The response contains a list of mappings with their last registered timestamp. This timestamp can be used in the synchronization process to figure out what has changed and what needs to be synced.


Then, a third party makes a request to the server using the following structure:

```
POST /nti/sync/pull
Content-Type: application/json
{
    "mappings": [
        { "mapping": "[MAPPING_NAME]", "timestamp": [LAST_TIMESTAMP_CHECKED] }
    ]
}
```

After receiving the request, if a mapping with the specified name exists, the system will call the repository's findFromTimestamp implementation and return the following result (Using a Product entity as an example):

```
{
    "[MAPPING_NAME]": {
        "changes": [
            {
                "id": 2,
                "productId": "POTATOBAG",
                "name": "Potato bag",
                "description": "Bag of potatoes",
                "price": "32.99",
                "cost": "0",
                "createdOn": "11/30/2017 04:22:49 PM",
                "updatedOn": "11/30/2017 04:22:49 PM",
                "lastTimestamp": 1515068439
            },
            ...
        ],
        "newItems": [
            {
                "id": 1,
                "uuid": "24a7aff0-fea8-4f62-b421-6f97f464f310",
                "mapping": {
                    "id": 1,
                    "name": "Product",
                    "class": "AppBundle\\Entity\\Product\\Product",
                    "sync_service": "AppBundle\\Service\\Product\\ProductService"
                },
                "class_id": 8,
                "timestamp": 1515068439
            },
            ...            
        ],
        "deletes": [
            {
                "id": 2,
                "mapping": {
                    "id": 2,
                    "name": "Product",
                    "class": "AppBundle\\Entity\\Product\\Product"
                },
                "classId": "[identifier_getter result]",
                "timestamp": 1512080746
            },
            ...
        ],
        "failedItems": [
            {
                "id": 7,
                "uuid": "abcdefg-123456-hifgxyz-78901",
                "mapping": {
                    "id": 9,
                    "name": "Product",
                    "class": "AppBundle\\Entity\\Product\\Product",
                    "sync_service": " ... "
                },
                "classId": 137,
                "timestamp": 1512080747,
                "errors": [...errors provided...]
            },
            ...
        ],
        "_real_last_timestamp": 1512092445
    }
}

```

The server will return the both the `changes` , `newItems`, `failedItems` , and the `deletes`. The `changes` will contain the `data` portion of the array returned by
the repository's implementation of `SyncRepositoryInterface`. The `deletes` will contain the list of `SyncDeleteState` that were recorded since the 
specified timestamp. The `newItems` will contain the list of `SyncNewItemState` which means the new items that were created since the provided timestamp
including the UUID that was given at the time (This is helpful to third party devices when first pulling the information they can verify if an item was already created
but they don't have the ID of that item in their local storage and avoid creating duplicates in the server). The `failedItems` will contain the list of `SyncFailedItemState`, each item in this list
contains an `errors` property with the errors founds processing the creation or update of the entity. 

The `_real_last_timestamp` should be used as it can help with paginating the results for a full-sync and help the client
get the real last timestamp of the last object in the response. This has to be obtained in the repository and can be done
by simply getting the last item from the repository's result and calling the `getLastTimestamp()`.

From this point on, the client must keep a track of the `_real_last_timestamp` in order to perform a sync in the future.

## Implementation (Push)

Below is the general idea over the push/pull process:

![Synchronization Process - Push/Pull](/Images/SynchronizationProcess-PushPull.PNG?raw=true "Synchronization Push Pull Process")

### Server Side
In the `SyncMapping` for each mapped entity a service should be specified. This service must implement the `SyncServiceInterface`. 

### Client Side
In order to handle a push from a third party device it must provide the following structure in its request:
 
```
POST /nti/sync/push
Content-Type: application/json
{
    "mappings": [
        { "mapping": "[MAPPING_NAME]", "data": [
            {
                "id": "5eb86d4a-9b82-42f3-abae-82b1b61ad58e",
                "serverId": 1,
                "name": "Product1",
                "description": "Description of the product",
                "price": 55,
                "lastTimestamp": 1512080746
            },
            ...
        ] }
    ]
}
```

When the server receives this request, it will execute the `sync()` method of the configured service in the respective `SyncMapping` and it will
pass the array of data for that parameter. Your `sync()` function needs to operate over this information and return an array which will be included
inside the response under the respective mapping name.

The server then returns the following structure:
```
{
    "mappings": [
        { "[MAPPING_NAME]": "RESULT OF YOUR sync() HERE" },
        { "[MAPPING_NAME]": "RESULT OF YOUR sync() HERE" },
        { "[MAPPING_NAME]": "RESULT OF YOUR sync() HERE" },
    ]
}
```

## Todo

* Handle deletes from third parties
* `ManyToMany` relationships are tricky and can lead to performance issues 
