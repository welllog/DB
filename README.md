# Welcome to orinfy's DB class
The DB class is a simple query builder class in PHP,it based on PDO,and require php version > 7.1.


## Usage
To use the class, just include it into your project files like this
```php
include 'DB.php';
```
Then you have to instantiate the class like this
```php
$db = \orinfy\DB::i();
```
### Insert values to a table
Insert a record
```php
$db->table('user')->insert(['name' => 'jack', 'age' => 24]);
```
Insert multiple records
```php
$db->table('user')->insert([['name' => 'linda', 'age' => 21], ['name' => 'bob', 'age' => 24]]);
```
Get insert record id
```php
$id = $db->table('user')->insertGetId(['name' => 'ailan', 'age' => 21]);
```
Restrict inserted columns
```php
$db->table('user')->allow('name', 'age')->insert(['name' => 'jack', 'age' => 24, 'job' => 'programmer']);
```
To see the SQL query that have executed, use the `getSQL()` Method like this:
```php
echo $db->getSQL();
```
Output :
```sql
'insert into `user` (`name`,`age`) values (?,?)'
```
### Update table values
```php
$db->table('user')->where('id', '=', 1)->update(['name' => 'remi']);
```
SQL Query :
```sql
'update `user` set `name`=? where `id`=?'
```
Increased
```php
$db->table('scores')->where('id', '=', 10)->increment('score');
$db->table('scores')->where('id', '=', 10)->increment('score', 5);
$db->table('scores')->where('id', '=', 10)->increment([['score', 1], ['level', 9]]);
```
Decrement
```php
$db->table('scores')->where('id', '=', 10)->decrement('score');
$db->table('scores')->where('id', '=', 10)->decrement('score', 2);
$db->table('scores')->where('id', '=', 10)->decrement([['score', 2], ['level', 1]]);
```
#### notice
**If there is no where，changes will not happened**

### Delete values from table
```php
$db->table('logs')->where([['id', '>', 9], ['level', '<', 2]])->delete();
```
#### notice
**If there is no where，nothing be deleted**

### Selection 
```php
$rows = $db->table('user')->get();
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
$user = $db->table('user')->first();
```
It returns a one-dimensional array
```plain
['name' => 'jack', 'age' => 21]
```
Get a list of records
```php
$userNames = $db->table("users")->pluck('username');
$userNames = $db->table("users")->pluck('username', 'id');
```
It returns a one-dimensional array
```plain
['job', 'jack']
[1 => 'job', 2 => 'jack']
```
get a field value
```php
echo $db->table('users')->where('id', '=', 2)->value('username');  // 'jack'
```
Aggregate function
```php
$db->table('user')->max('age');
$db->table('user')->min('age');
$db->table('user')->sum('age');
$db->table('user')->count();
$db->table('user')->avg('age');
```
#### use where 
```php
$db->table('user')->where('id', 10)->where('level', '=', 5)->get();
$db->table('user')->where([['id', '>', 10], ['level', '=', 5]])->orWhere('status', '=', 0)->get();
$db->table('user')->where([['id', '>', 10], ['level', '=', 5]])->orWhere('status', '=', 0)
    ->orWhere(function ($query) {
        $query->whereNull('username')
    })->get();
```
where function
```php
whereNull('username')
whereNotNull('username')
whereIn('id', [1, 2, 3])
whereNotIn('id', [1, 2, 3])
whereBetween('id', [1, 9])
whereNotBetween('id', [1, 9])
whereColumn('id', '>', 'parent_id')
where('`username`=? and `age`>?', ['job', 23])
where('username', '=', 'job')->where('age', '>', 23)
where([['username', '=', 'job'], ['age', '>', 23]])
whereRaw('`id`>? and `status`=?', [10, 1])
```
#### use select
filter columns
```php
$db->table('users')->select('id', 'username', 'age')->get();
$db->table('users')->select(['id', 'username', 'age'])->get();
$db->table('users')->select(['id', 'username', 'age', 'sum(score) as total'])->get();
```
#### use join
join function
```php
$db->table('user as u')->join('article as a', 'u.id', '=', 'a.uid')->select('u.*', 'a.commend')->get();
```
SQL Query :
```sql
'select  `test_u`.`*`,`test_a`.`commend` from `test_user` as `test_u`  inner join `test_article` as `test_a` on `test_u`.id=`test_a`.uid'
```
you can olso use 'leftJoin' and 'rightJoin' and native sql
```php
$db->table('user')->leftJoin('role', 'user.role_id', '=', 'role.id')->rightJoin('posts', 'uid', '=', 'user.id');
$db->table('user')->join('role', 'test_user.role_id=test_role.id and test_role.status>?', [1]);
```
#### order by,group by...
```php
$db->table('user')->orderBy('id', 'desc')->get();
$db->table('user')->orderBy('id', 'asc')->get();
$db->table('user')->select('count(id) as num')->groupBy('team_id')->having('num', '>', 2)->get();
$db->table('user')->select('count(id) as num')->groupBy('team_id')->having('`num`>?', [2])->get();
$db->table('user')->limit(2)->get();
$db->table('user')->limit(5, 2)->get();
```
### transaction
oringfy/DB provide transaction common API
```php
$db->beginTrans();
$db->inTrans();
$db->rollBack();
$db->commit();
```
### native sql
orinfy/DB also support native sql
```php
$db->exec('delete from test_user');
$db->query('select * from test_user')->fetchAll();
$db->query('select * from test_user')->fetch();
$db->prepare('select * from test_user where id>?')->execute([10])->fetch();
$db->prepare('update test_user set username=?')->execute(['aiwa'])->rowCount();
$db->prepare('insert into test_user (username) values(?)')->execute(['aiwa'])->lastInsertId();
```
you could get PDO to use
```php
$pdo = $db->getPdo();
```

###### tips
If you have any questions please contact orinfy@foxmail.com
