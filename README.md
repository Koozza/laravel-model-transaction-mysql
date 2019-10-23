## Laravel Model Transactions (MySQL)
[![License](https://poser.pugx.org/koozza/laravel-model-transaction-mysql/license)](https://packagist.org/packages/koozza/laravel-model-transaction-mysql)
[![Latest Unstable Version](https://poser.pugx.org/koozza/laravel-model-transaction-mysql/v/unstable)](https://packagist.org/packages/koozza/laravel-model-transaction-mysql)

## Installation

Require this package with composer. It is recommended to only require the package for development.

```shell
composer require koozza/laravel-model-transaction-mysql
```

Laravel 5.5 uses Package Auto-Discovery, so doesn't require you to manually add the ServiceProvider.

### Laravel 5.5+:

If you don't use auto-discovery, add the ServiceProvider to the providers array in config/app.php

```php
Koozza\ModelTransaction\ServiceProvider::class,
```

## Usage

You can now use transactions for models. You can start a transaction with 

```php
ModelTransaction::start();
```

And finish / flush the transaction with

```php
ModelTransaction::flush();
```

You can change the default chunk size with: (Default 250)

```php
ModelTransaction::setMaxModelsPerQuery(1000);
```

You can enable or disable the touching of timestamps with: (Default true)

```php
ModelTransaction::setTouchTimestamps(false);
```

## Performance

1000 inserts in database measured in seconds. Measured on local development machine.

|   	|DB::Insert|Model::save() w/o transaction|Model::save() with transaction|
|---	|---	|---	|---	|
|1st run|7.515896|6.841657|0.292675|
|2nd run|7.756263|7.088850|0.287324|
|3th run|7.511990|7.347801|0.262755|
|4th run|7.598718|6.671702|0.206389|
|AVG|7.595725|6.9875025|**0.262286**|

1000 updates in database measured in seconds. Measured on local development machine.

|   	|DB::Update|Model::update() w/o transaction|Model::updte() with transaction|
|---	|---	|---	|---	|
|1st run|6.604472|7.098696|0.3033712|
|2nd run|6.619229|8.574101|0.2813890|
|3th run|6.580817|7.500146|0.3042352|
|4th run|7.031743|6.644698|0.2901800|
|AVG|6.709065|7.454410|**0.294794**|
