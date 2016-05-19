<?php

namespace Dfba\Schema;

use PDO;
use Carbon\Carbon as Date;

class Factory {

	protected $cachedSchemas = [];

	public function newSchema(array $attributes=[]) {
		return new Schema($this, $attributes);
	}

	protected function newDateFromString($string, $format) {
		return Date::createFromFormat($format, $string);
	}

	public function getSchemaFromCache(PDO $pdo, $schemaName) {

		foreach ($this->cachedSchemas as $cachedSchema) {
			if ($cachedSchema->getPdo() == $pdo && $cachedSchema->getName() == $schemaName) {
				return $cachedSchema;
			}
		}

		return null;
	}

	public function clearCache($pdo=null) {

		if ($pdo) {

			foreach ($this->cachedSchemas as $i => $cachedSchema) {
				if ($cachedSchema->getPdo() == $pdo) {
					array_splice($this->cachedSchemas, $i, 1);
					break;
				}
			}

		} else {
			$this->cachedSchemas = [];
		}
		
	}

	protected function addSchemaToCache(Schema $schema) {

		$this->cache[] = $schema;

	}

	public function getSchema(PDO $pdo, $schemaName) {

		$schema = $this->getSchemaFromCache($pdo, $schemaName);

		if ($schema) {
			return $schema;
		}


		$schema = $this->fetchSchema($pdo, $schemaName);
		$this->addSchemaToCache($schema);

		return $schema;
	}

	protected function fetchSchema(PDO $pdo, $schemaName) {

		$schemaAttributes = $this->querySchemas($pdo, [$schemaName]);
		
		if (count($schemaAttributes)) {
			$schema = $this->newSchema($schemaAttributes[0]);

			foreach ($this->queryTables($pdo, $schema) as $tableAttributes) {
				$table = $schema->newTable($tableAttributes);

				foreach ($this->queryColumns($pdo, $table) as $columnAttributes) {
					$column = $table->newColumn($columnAttributes);

					$table->addColumn($column);
				}

				$schema->addTable($table);
			}

			return $schema;

		} else {
			return null;
		}
	}

	protected function executeSelectQuery(PDO $pdo, $query, array $parameters=[]) {

		$statement = $pdo->prepare($query);
		$statement->execute($parameters);
		return $statement->fetchAll(PDO::FETCH_ASSOC);
		
	}

	protected function sqlIn(&$parameters, $column, $values) {
		if (is_array($values)) {

			if (count($values)) {
				$parameters = array_merge($parameters, $values);
				return "$column IN (". implode(',', array_fill(0, count($values), '?')) .")";

			} else {
				return "(0=1)";
			}

		} else {
			return "(1=1)";
		}
	}

	protected function querySchemas(PDO $pdo, $schemas=null) {

		$parameters = [];

		$conditions = $this->sqlIn($parameters, '`SCHEMA_NAME`', $schemas);

		return $this->executeSelectQuery($pdo, "SELECT 
				`SCHEMA_NAME` AS `name`,
				`DEFAULT_CHARACTER_SET_NAME` AS `characterSet`,
				`DEFAULT_COLLATION_NAME` AS `collation`
			FROM `INFORMATION_SCHEMA`.`SCHEMATA`
			WHERE 
				`SCHEMA_NAME` NOT IN (
					'information_schema',
					'mysql',
					'performance_schema',
					'sys'
				) AND $conditions
			ORDER BY `SCHEMA_NAME` ASC", $parameters);
	}

	protected function queryTables(PDO $pdo, Schema $schema, $tables=null) {

		$parameters = [$schema->getName()];

		$conditions = $this->sqlIn($parameters, '`TABLE_NAME`', $tables);

		return $this->executeSelectQuery($pdo, 
			"SELECT 
				`TABLE_NAME` AS `name`,
				`ENGINE` AS `engine`,
				`CHARACTER_SET_NAME` AS `characterSet`,
				`TABLE_COLLATION` AS `collation`,
				`TABLE_COMMENT` AS `comment`
			FROM `INFORMATION_SCHEMA`.`TABLES`
			LEFT JOIN `INFORMATION_SCHEMA`.`COLLATION_CHARACTER_SET_APPLICABILITY` ON 
				`TABLES`.`TABLE_COLLATION` = `COLLATION_CHARACTER_SET_APPLICABILITY`.`COLLATION_NAME`
			WHERE `TABLE_TYPE` = 'BASE TABLE' AND `TABLE_SCHEMA` = ? AND $conditions
			ORDER BY `TABLE_NAME` ASC", $parameters);
	}

	protected function queryColumns(PDO $pdo, Table $table, $columns=null) {

		$parameters = [$table->getSchema()->getName(), $table->getName()];

		$conditions = $this->sqlIn($parameters, '`COLUMN_NAME`', $columns);

		$columnResults = $this->executeSelectQuery($pdo, 
			"SELECT
				`COLUMN_NAME` AS `name`,
				`COLUMN_DEFAULT` AS `defaultValue`,
				`IS_NULLABLE` AS `nullable`,
				`DATA_TYPE` AS `dataType`,
				`CHARACTER_MAXIMUM_LENGTH` AS `maximumLength`,
				`CHARACTER_SET_NAME` AS `characterSet`,
				`COLLATION_NAME` AS `collation`,
				`COLUMN_TYPE` AS `type`,
				`EXTRA` AS `extra`,
				`COLUMN_COMMENT` AS `comment`
			FROM `INFORMATION_SCHEMA`.`COLUMNS`
			WHERE `TABLE_SCHEMA` = ? AND `TABLE_NAME` = ? AND $conditions
			ORDER BY `ORDINAL_POSITION` ASC",
			$parameters);

		$columnAttributes = [];
		foreach ($columnResults as $result) {
			$columnAttributes[] = $this->parseInformationSchemaColumn($result);
		}

		return $columnAttributes;
	}

	protected function getMaximumValue($columnAttributes) {
		$dataType = $columnAttributes['dataType'];

		if ($columnAttributes['unsigned']) {
			switch ($dataType) {
				case 'tinyint':
					return '255';
				case 'smallint':
					return '65535';
				case 'mediumint':
					return '16777215';
				case 'int':
					return '4294967295';
				case 'bigint':
					return '18446744073709551615';
			}
		} else {
			switch ($dataType) {
				case 'tinyint':
					return '127';
				case 'smallint':
					return '32767';
				case 'mediumint':
					return '8388607';
				case 'int':
					return '2147483647';
				case 'bigint':
					return '9223372036854775807';
			}
		}

		return null;
	}

	protected function getMinimumValue($columnAttributes) {
		$dataType = $columnAttributes['dataType'];

		if ($columnAttributes['unsigned']) {
			switch ($dataType) {
				case 'tinyint':
				case 'smallint':
				case 'mediumint':
				case 'int':
				case 'bigint':
					return '0';
			}
		} else {
			switch ($dataType) {
				case 'tinyint':
					return '-128';
				case 'smallint':
					return '-32768';
				case 'mediumint':
					return '-8388608';
				case 'int':
					return '-2147483648';
				case 'bigint':
					return '-9223372036854775808';
			}
		}

		return null;
	}


	protected function parseInformationSchemaColumn($attributes) {

		$column = [
			'name' => $attributes['name'],
			'dataType' => strtolower($attributes['dataType']),
			'characterSet' => $attributes['characterSet'],
			'collation' => $attributes['collation'],
			'comment' => null,
			'maximumLength' => null,
			'nullable' => $attributes['nullable'] == 'YES',
			'autoIncrement' => false,
			'unsigned' => false,
			'zerofill' => false,
			'defaultValue' => $attributes['defaultValue'],
			'options' => null,
		];

		if ($attributes['maximumLength'] !== null) {
			$column['maximumLength'] = intval($attributes['maximumLength']);
		}

		if (strpos($attributes['extra'], 'auto_increment') !== false) {
			$column['autoIncrement'] = true;
		}

		if (strlen($attributes['comment']) > 0) {
			$column['comment'] = (string) $attributes['comment'];
		}

		if (strpos($attributes['type'], 'unsigned') !== false) {
			$column['unsigned'] = true;
		}

		if (strpos($attributes['type'], 'zerofill') !== false) {
			$column['zerofill'] = true;
		}

		if ($column['defaultValue'] !== null) {

			switch ($column['dataType']) {
				case 'date':
					$column['defaultValue'] = $this->newDateFromString($column['defaultValue'], 'Y-m-d');
					break;

				case 'datetime':
					$column['defaultValue'] = $this->newDateFromString($column['defaultValue'], 'Y-m-d H:i:s');
					break;

				case 'timestamp':
					$column['defaultValue'] = $this->newDateFromString($column['defaultValue'], 'U');
					break;
			}

		}


		if ($column['dataType'] == 'enum' || $column['dataType'] == 'set') {
			$column['options'] = explode("','", str_replace("''", "'", preg_replace("/(enum|set)\('(.+?)'\)/","\\2", $attributes['type'])));
		}

		$column['minimumValue'] = $this->getMinimumValue($column);
		$column['maximumValue'] = $this->getMaximumValue($column);

		return $column;
	}

	

}