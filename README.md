# Welcome to Odb
The DB class is a simple query builder class in PHP,it based on PDO,and require php version > 7.1.


## Usage
First load your database config
```php
\Odb\DB::loadConfig("DBConf.php");
```
database config like this:
```php
return [
    'default' => [     // default must exists
        'driver' => 'mysql',   // pgsql(postgresql)
        'host' => '127.0.0.1',
        'port' => '3306',
        'username' => '',
        'password' => '',
        'dbname' => '',
        'charset' => 'utf8',
        'pconnect' => false,
        'time_out' => 3,
        'prefix' => '',
        'throw_exception' => true
    ]
];
```
We use the default "default" connection, so the default configuration is required.
Then you have to instantiate the class like this
```php
\Odb\DB::connect()->getDBVersion();
```
Switching other connection configurations
```php
\Odb\DB::connect('default')->query(...)->get();
```
### Insert values to a table
Insert a record
```php
\Odb\DB::table('user')->insert(['name' => 'jack', 'age' => 24]);
```
Insert multiple records
```php
\Odb\DB::table('user')->insert([['name' => 'linda', 'age' => 21], ['name' => 'bob', 'age' => 24]]);
```
Get insert record id
```php
$id = \Odb\DB::table('user')->insertGetId(['name' => 'ailan', 'age' => 21]);
```
Restrict inserted columns
```php
\Odb\DB::table('user')->allow('name', 'age')->insert(['name' => 'jack', 'age' => 24, 'job' => 'programmer']);
```
To see the SQL query that have executed, use the `getSQL()` Method like this:
```php
echo \Odb\DB::table('user')->buildInsert(['name' => 'jack', 'age' => 24])->getSQL();
```
Output :
```sql
'insert into `user` (`name`,`age`) values (?,?)'
```
```php
echo \Odb\DB::table('user')->buildInsert(['name' => 'jack', 'age' => 24])->getRSql();
```
Output :
```sql
'insert into `user` (`name`,`age`) values ('jack', 24)'
```
### Update table values
```php
\Odb\DB::table('user')->where('id', 1)->update(['name' => 'remi']);
```
SQL Query :
```sql
'update `user` set `name`=? where `id`=?'
```
Increased
```php
\Odb\DB::table('scores')->where('id', 10)->increment('score');
\Odb\DB::table('scores')->where('id', '=', 10)->increment('score', 5);
\Odb\DB::table('scores')->where('id', '=', 10)->increment([['score', 1], ['level', 9]]);
```
Decrement
```php
\Odb\DB::table('scores')->where('id', '=', 10)->decrement('score');
\Odb\DB::table('scores')->where('id', '=', 10)->decrement('score', 2);
\Odb\DB::table('scores')->where('id', '=', 10)->decrement([['score', 2], ['level', 1]]);
```
#### notice
**If there is no where，changes will not happened**

### Delete values from table
```php
\Odb\DB::table('logs')->where([['id', '>', 9], ['level', '<', 2]])->delete();
```
#### notice
**If there is no where，nothing be deleted**

### Selection 
```php
$rows = \Odb\DB::table('user')->get();
```
It returns a Two-dimensional array
Output : 
```plain
[
    ['name' => 'jack', 'age' => 21],
    ['name' => 'bob', 'age' => 23]
]
```
Get a row record
 ```php
$user = \Odb\DB::table('user')->first();
```
It returns a one-dimensional array
```plain
['name' => 'jack', 'age' => 21]
```
Get a list of records
```php
$userNames = \Odb\DB::table("users")->pluck('username');
$userNames = \Odb\DB::table("users")->pluck('username', 'id');
```
It returns a one-dimensional array
```plain
['job', 'jack']
[1 => 'job', 2 => 'jack']
```
get a field value
```php
echo \Odb\DB::table('users')->where('id', '=', 2)->value('username');  // 'jack'
```
Aggregate function
```php
\Odb\DB::table('user')->max('age');
\Odb\DB::table('user')->min('age');
\Odb\DB::table('user')->sum('age');
\Odb\DB::table('user')->count();
\Odb\DB::table('user')->avg('age');
```
#### use where 
```php
\Odb\DB::table('user')->where('id', 10)->where('level', '=', 5)->get();
\Odb\DB::table('user')->where([['id', '>', 10], ['level', '=', 5]])->orWhere('status', '=', 0)->get();
\Odb\DB::table('user')->where([['id', '>', 10], ['level', '=', 5]])->orWhere('status', '=', 0)
    ->orWhere(function ($query) {
        $query->whereNull('username');
    })->get();
```
where function
```php
whereNull('username');
whereNotNull('username');
whereIn('id', [1, 2, 3]);
whereNotIn('id', [1, 2, 3]);
whereBetween('id', [1, 9]);
whereNotBetween('id', [1, 9]);
whereColumn('id', '>', 'parent_id');
where('`username`=? and `age`>?', ['job', 23]);
where('username', '=', 'job')->where('age', '>', 23);
where([['username', '=', 'job'], ['age', '>', 23]]);
whereRaw('`id`>? and `status`=?', [10, 1]);
```
#### use select
filter columns
```php
\Odb\DB::table('users')->select('id', 'username', 'age')->get();
\Odb\DB::table('users')->select(['id', 'username', 'age'])->get();
\Odb\DB::table('users')->select(['id', 'username', 'age', 'sum(score) as total'])->get();
```
#### use join
join function
```php
\Odb\DB::table('user as u')->join('article as a', 'u.id', '=', 'a.uid')->select('u.*', 'a.commend')->get();
```
SQL Query :
```sql
'select  `test_u`.`*`,`test_a`.`commend` from `test_user` as `test_u`  inner join `test_article` as `test_a` on `test_u`.id=`test_a`.uid'
```
you can olso use 'leftJoin' and 'rightJoin' and native sql
```php
\Odb\DB::table('user')->leftJoin('role', 'user.role_id', '=', 'role.id')->rightJoin('posts', 'uid', '=', 'user.id');
\Odb\DB::table('user')->join('role', 'test_user.role_id=test_role.id and test_role.status>?', [1]);
```
#### order by,group by...
```php
\Odb\DB::table('user')->orderBy('id', 'desc')->get();
\Odb\DB::table('user')->orderBy('id', 'asc')->get();
\Odb\DB::table('user')->select('count(id) as num')->groupBy('team_id')->having('num', '>', 2)->get();
\Odb\DB::table('user')->select('count(id) as num')->groupBy('team_id')->having('`num`>?', [2])->get();
\Odb\DB::table('user')->limit(2)->get();
\Odb\DB::table('user')->limit(5, 2)->get();
```
### transaction
oringfy/DB provide transaction common API
```php
\Odb\DB::beginTrans();
\Odb\DB::inTrans();
\Odb\DB::rollBack();
\Odb\DB::commit();
```
### native sql
orinfy/DB also support native sql
```php
\Odb\DB::exec('delete from test_user');
\Odb\DB::query('select * from test_user')->get();
\Odb\DB::prepare('select * from test_user where id>?')->execute([10])->get();
\Odb\DB::prepare('update test_user set username=?')->execute(['aiwa'])->rowCount();
\Odb\DB::prepare('insert into test_user (username) values(?)')->execute(['aiwa'])->lastInsertId();
```

###### tips
If you have any questions please contact orinfy@foxmail.com
