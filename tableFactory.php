<?php
class TableFactory{
	
	function buildTable($tableName, $mysqli){
		if(file_exists(dirname(__FILE__).'/tables/'.$tableName.'.php')){
			include_once dirname(__FILE__).'/tables/'.$tableName.'.php';
			return new $tableName($mysqli, $this);
		} else {
			include_once 'table.php';
			return new Table($tableName, $mysqli, $this);
		}
	}
} 

?>