<?php

class stdObject {
    public function __construct(array $arguments = array()) {
        if (!empty($arguments)) {
            foreach ($arguments as $property => $argument) {
                $this->{$property} = $argument;
            }
        }
    }
    public function __call($method, $arguments) {
        $arguments = array_merge(array("stdObject" => $this), $arguments); 
        if (isset($this->{$method}) && is_callable($this->{$method})) {
            return call_user_func_array($this->{$method}, $arguments);
        } else {
            throw new Exception("Fatal error: Call to undefined method {$this}::{$method}()");
        }
    }
}

class Table extends stdObject{
	protected $name;
	protected $fields;
	protected $db_link;
	protected $id; 
	protected $field_count;
	protected $filters;
	protected $order;
	protected $cursorColumns;
	protected $limit;
	protected $onInsert=true;
	protected $onUpdate=true;
	protected $onDelete=true;
	protected $tF;
	protected $secureInput=true;
	
	function __construct($name, $db_link, $tableFactory=null){
		
		$this->db_link = $db_link;
		$this->name = $this->secureValue($name);
		$this->field_count=0;
		$this->filters = array();
		$this->order = null;
		$this->cursorColumns = null;
		$this->limit = array();
		$this->tF = $tableFactory;
		
		$query = "SHOW COLUMNS FROM ".$this->name;
		$result = $this->db_link->prepare($query);
		if(!$result){
			throw new Exception("Error: Can't get info of table ".$this->name);
		};
		$result->execute();
		if($this->db_link->error){
			throw new Exception($this->db_link->error);
		};
		$result->bind_result($Name, $Type, $Null, $Key, $Default, $Extra);
		$this->fields = array();
		while ($result->fetch())
		{
			
			if(strpos($Type,"(")>0){
				$ttype=explode("(",$Type);
				$Type = strtoupper($ttype[0]);
				$Length = str_replace(")","",$ttype[1]);
			}
			
			$this->fields[$Name] = array(
											'Name' => $Name,
											'Type' => $Type,
											'Length'=> $Length==''?0:$Length,
											'Null' => $Null,
											'Key' => $Key,
											'Default' => $Default,
											'Extra' => $Extra,
											'Value' =>isset($Default)?$Default:null,
											'oldValue' =>isset($Default)?$Default:null,
											'Quotes'=>$this->getQuotes($Type)
									);
					
			if(($Key=="PRI")){
				$this->id = $Name;
			}
			$this->field_count++;
			
			$this->{"set_" . ucfirst($Name)} = function($stdObject, $value) use ($Name){
				 $stdObject->setFieldValue($Name, $value);
			};
			$this->{"get_" . ucfirst($Name)} = function($stdObject) use ($Name){
				 return $stdObject->getFieldValue($Name);
			};
			$this->{"getOld_" . ucfirst($Name)} = function($stdObject) use ($Name){
				 return $stdObject->getFieldOldValue($Name);
			};
			
		}
		
	}
	protected function onInsert(){}
	protected function onUpdate(){}
	protected function onDelete(){}
	
	function skipOnInsert($param=true){$this->onInsert=!$param;}
	function skipOnUpdate($param=true){$this->onUpdate=!$param;}
	function skipOnDelete($param=true){$this->onDelete=!$param;}  
	
	function getTableName(){
		return $this->name;
	}
	
	function init(){
		 foreach($this->fields as $field){
			$field['Value'] = isset($field['Default'])?$field['Default']:null;
			$field['oldValue'] = isset($field['Default'])?$field['Default']:null;
		} 
	}
	
	function insert($commit=true){
		$this->db_link->autocommit(FALSE);
		$insertId = null;
		try{
			if($this->onInsert){$this->onInsert();}
	
			$fieldnames=array();
			$fieldvalues=array();
			foreach($this->fields as $field){
					$fieldnames[]=$field['Name'];
					if(isset($field['Value'])&&($field['Name']!=$this->id)){
						$fieldvalues[]=$field['Quotes'].$this->secureValue($field['Value']).$field['Quotes'];
					} else{
						$fieldvalues[]='NULL';
					}
			};
		
			$query = "INSERT INTO ".$this->name." (".implode(",", $fieldnames).") VALUES (".implode(",", $fieldvalues).")";
			if(!$this->db_link->query($query)){
				throw new Exception('MySQL Error:'.$this->db_link->error);
			};
			$insertId = $this->db_link->insert_id;
			if($commit){$this->db_link->commit();}
			return $insertId;
		} catch (Exception $e){
			$this->db_link->rollback();
			throw $e;
		} finally {
			$this->db_link->autocommit($commit);
		}
		return false;	
	}
	
	
	
	function bulkInsert($records=array(), $commit=true){
		if(empty($records)){return;}
		$this->db_link->autocommit(FALSE);
		$this_saved_state = $this->getRecordAsArray();
		$values_str=array();
		$fieldnames=array();
		foreach($this->fields as $field){
					$fieldnames[]=$field['Name'];
		}		
		try{
			foreach($records as $rec){
				$this->copyFieldValuesFrom($rec);
				$this->set_Id(null);
				if($this->onInsert){$this->onInsert();}
				$values_str[]='('.implode(",",$this->suitArrayForQuery($this->getRecordAsArray())).')';
			}
			$query = "INSERT INTO ".$this->name." (".implode(",", $fieldnames).") VALUES ".implode(",",$values_str);
			if(!$this->db_link->query($query)){
				throw new Exception('MySQL Error:'.$this->db_link->error);
			};
			if($commit){$this->db_link->commit();}
		} catch (Exception $e){
			$this->db_link->rollback();
			throw $e;
		} finally {
			$this->copyFieldValuesFrom($this_saved_state);
			$this->db_link->autocommit($commit);
		}
		
	}	
	
	function deleteByID($id, $commit=true){
		$this->db_link->autocommit(FALSE);
		try{
			if($this->onDelete){$this->onDelete();}
		
			$query = "DELETE FROM ".$this->name." WHERE ".$this->id."=".$this->secureValue($id);
			if(!$this->db_link->query($query)){
				throw new Exception('MySQL Error:'.$this->db_link->error);
			};
			if($commit){$this->db_link->commit();}
		} catch (Exception $e){
			$this->db_link->rollback();
			throw $e;
		} finally {
			$this->db_link->autocommit($commit);		
		}
	}
	
	protected function secureValue($value){
		return $this->secureInput?$this->db_link->real_escape_string($value):$value;
	}
	function setSecureInput($param=true){
		$this->secureInput=$param;
	}
	function getById($id){
		$query = "SELECT * FROM ".$this->name." WHERE ".$this->id."=".$this->secureValue($id)." LIMIT 1";
		$result = $this->db_link->query($query);
		$row = $result->fetch_assoc();
		if(is_null($row)){return false;};
		foreach($this->fields as $field){
			$this->setFieldValue($field['Name'],$row[$field['Name']]);
		};
		return true;
	}
	
	function update($commit=true){
		$this->db_link->autocommit(FALSE);
		try{
			if($this->onUpdate){$this->onUpdate();}
			
			$fieldsforupdate=array();
			foreach($this->fields as $field){
				if(($field['Name']!=$this->id)){
					if(!is_null($field['Value'])){
						$fieldsforupdate[]=$field['Name']."=".$field['Quotes'].$this->secureValue($field['Value']).$field['Quotes'];
					} else{
						$fieldsforupdate[]=$field['Name']."=NULL";
					}
				}
				
				
			};
			$query = "UPDATE ".$this->name." SET ".implode(",", $fieldsforupdate)." WHERE ".$this->id."=".$this->secureValue($this->fields[$this->id]['Value']);
			if(!$this->db_link->query($query)){
				throw new Exception('MySQL Error:'.$this->db_link->error);
			};
			if($commit){$this->db_link->commit();}
		} catch (Exception $e){
			$this->db_link->rollback();
			throw $e;
		} finally {
			$this->db_link->autocommit($commit);		
		}
		
	}	
	
	function setFieldValue($fieldName,$fieldValue){
		if(!isset($this->fields[$fieldName])){
				throw new Exception("Column ".$fieldName." not found in table ".$this->name);
		};
		$this->fields[$fieldName]['oldValue'] = $this->fields[$fieldName]['Value'];
		$this->fields[$fieldName]['Value'] = $this->isStringType($fieldName)?strip_tags($fieldValue):$fieldValue;
	}
	
	function getFieldValue($fieldName){
		if(!isset($this->fields[$fieldName])){
				throw new Exception("Column ".$fieldName." not found in table ".$this->name);
		};
		return $this->fields[$fieldName]['Value'];
	}
	
	function getFieldOldValue($fieldName){
		if(!isset($this->fields[$fieldName])){
				throw new Exception("Column ".$fieldName." not found in table ".$this->name);
		};
		return $this->fields[$fieldName]['oldValue'];
	}
	
	function getSumColumn($column){
		if(!isset($this->fields[$fieldName])){
				throw new Exception("Column ".$fieldName." not found in table ".$this->name);
		};
		if(!$this->isNumericType($column)){
			throw new Exception("Can't get SUM column ".$fieldName.". It is not numeric type in table ".$this->name);
		};
		$query = 'SELECT SUM('.$column.') as s FROM '
				.$this->name
				.$this->buildWhere();
		$result = $this->db_link->query($query);
		$row = $result->fetch_assoc();
		return is_null($row['s'])?0:$row['s'];
		
	}
	function getAvgColumn($column){
		if(!isset($this->fields[$fieldName])){
				throw new Exception("Column ".$fieldName." not found in table ".$this->name);
		};
		if(!$this->isNumericType($column)){
			throw new Exception("Can't get AVG column ".$fieldName.". It is not numeric type in table ".$this->name);
		};
		$query = 'SELECT AVG('.$column.') as a FROM '
				.$this->name
				.$this->buildWhere();
		$result = $this->db_link->query($query);
		$row = $result->fetch_assoc();
		return is_null($row['a'])?0:$row['a'];
		
	}
	function getMinColumn($column){
		if(!isset($this->fields[$fieldName])){
				throw new Exception("Column ".$fieldName." not found in table ".$this->name);
		};
		
		$query = 'SELECT MIN('.$column.') as m FROM '
				.$this->name
				.$this->buildWhere();
		$result = $this->db_link->query($query);
		$row = $result->fetch_assoc();
		return is_null($row['m'])?null:$row['m'];
		
	}
	function getMaxColumn($column){
		if(!isset($this->fields[$fieldName])){
				throw new Exception("Column ".$fieldName." not found in table ".$this->name);
		};
		
		$query = 'SELECT MAX('.$column.') as m FROM '
				.$this->name
				.$this->buildWhere();
		$result = $this->db_link->query($query);
		$row = $result->fetch_assoc();
		return is_null($row['m'])?null:$row['m'];
		
	}
	protected function isNumericType($fieldName){
		$numericTypes = array('DECIMAL','INT','BIGINT','DOUBLE','FLOAT','MEDIUMINT','REAL','SMALLINT','TINYINT');
		return in_array($this->fields[$fieldName]['Type'], $numericTypes);
	}
	protected function isStringType($fieldName){
		$stringTypes = array('CHAR','JSON','VARCHAR','NVARCHAR','TEXT','LONGTEXT','MEDIUMTEXT','TINYTEXT');
		return in_array($this->fields[$fieldName]['Type'], $stringTypes);
	}
	protected function getQuotes($type){
		switch(strtoupper($type)){
			case 'CHAR':
			case 'NVARCHAR':
			case 'VARCHAR':
			case 'DATETIME':
			case 'DATE':
			case 'TIME':
			case 'TIMESTAMP':
			case 'TEXT':
			case 'YEAR':
					return "'";	
					break;
			default: 
					return "";
		}
	}
	
	function fields(){
		return $this->fields;
	}
	
	function id(){
		return $this->id;
	}
	
	function resetFilters(){
		$this->filters=array();
		$this->order=null;
		$this->cursorColumns = null;
		$this->limit=array();
	}
	
	function setFilter($column, $filterType, $filterValue1=null, $filterValue2=''){
		if(!isset($this->fields[$column])){
				throw new Exception("Error: column ".$column." not found in table ".$this->name);
		};
						
		switch(strtoupper($filterType)){
			case 'IS NULL':
			case 'IS NOT NULL':
				$this->filters[]=' '.$column.' '.strtoupper($filterType);
				return;
		}
		
		if(is_null($filterValue1)){return;};
		
		switch(strtoupper($filterType)){
			case '=':
						$this->filters[]= ' '.$column.'='.$this->getQuotes($this->fields[$column]['Type']).$this->secureValue($filterValue1).$this->getQuotes($this->fields[$column]['Type']).' ';
						break;
			case '>':
						$this->filters[]= ' '.$column.'>'.$this->getQuotes($this->fields[$column]['Type']).$this->secureValue($filterValue1).$this->getQuotes($this->fields[$column]['Type']).' ';
						break;
			case '>=':
						$this->filters[]= ' '.$column.'>='.$this->getQuotes($this->fields[$column]['Type']).$this->secureValue($filterValue1).$this->getQuotes($this->fields[$column]['Type']).' ';
						break;
			case '<':
						$this->filters[]= ' '.$column.'<'.$this->getQuotes($this->fields[$column]['Type']).$this->secureValue($filterValue1).$this->getQuotes($this->fields[$column]['Type']).' ';
						break;
			case '<=':
						$this->filters[]= ' '.$column.'<='.$this->getQuotes($this->fields[$column]['Type']).$this->secureValue($filterValue1).$this->getQuotes($this->fields[$column]['Type']).' ';
						break;
			case '<>':
						$this->filters[]= ' '.$column.'<>'.$this->getQuotes($this->fields[$column]['Type']).$this->secureValue($filterValue1).$this->getQuotes($this->fields[$column]['Type']).' ';
						break;
			case 'BETWEEN':
					if($filterValue2!=''){
						$this->filters[]= ' ('.$column.'>='.$this->getQuotes($this->fields[$column]['Type']).$this->secureValue($filterValue1).$this->getQuotes($this->fields[$column]['Type']).' AND '
						.$column.'<='.$this->getQuotes($this->fields[$column]['Type']).$this->secureValue($filterValue2).$this->getQuotes($this->fields[$column]['Type']).') ';
					};
						break;
			case 'NOT BETWEEN':
					if($filterValue2!=''){
						$this->filters[]= ' NOT ('.$column.'>='.$this->getQuotes($this->fields[$column]['Type']).$this->secureValue($filterValue1).$this->getQuotes($this->fields[$column]['Type']).' AND '
						.$column.'<='.$this->getQuotes($this->fields[$column]['Type']).$this->secureValue($filterValue2).$this->getQuotes($this->fields[$column]['Type']).') ';
					};
						break;			
			case 'IN':
			case 'NOT IN':
					if(is_array($filterValue1)){
						if(empty($filterValue1)){return;}
						$tmp = array();
						
						foreach($filterValue1 as $value){
							$tmp[] = $this->getQuotes($this->fields[$column]['Type']).$this->secureValue($value).$this->getQuotes($this->fields[$column]['Type']);
						};
					$this->filters[]=' '.$column.' '.strtoupper($filterType).' ('.implode(",",$tmp).') ';					
					}else{
						if(($filterValue1=='')||is_null($filterValue1)){return;}
						$this->filters[]=' '.$column.' '.strtoupper($filterType).' ('.$this->secureValue($filterValue1).') ';
					};
					break;
			case 'LIKE':
			case 'NOT LIKE':
					$this->filters[]=' '.$column.' '.strtoupper($filterType).' \'%'.$this->secureValue($filterValue1).'%\' ';
					break;
			case 'LIKE %_':		
					$this->filters[]=' '.$column.' LIKE \'%'.$this->secureValue($filterValue1).'\' ';
					break;
			case 'LIKE _%':		
					$this->filters[]=' '.$column.' LIKE \''.$this->secureValue($filterValue1).'%\' ';
					break;
			default: throw new Exception("Error: operator ".$filterType." undefined for filtering.");		
		}		
		return;
	}
	
	function setOrder($column,$orderType='ASC'){
		if(!isset($this->fields[$column])){
				throw new Exception("Error: can't setOrder for table ".$this->name." - column ".$column." not found.");
		};
		
		switch(strtoupper($orderType)){
			case 'ASC':
					$this->order = ' ORDER BY '.$column.' ASC';
					break;
			case 'DESC':
					$this->order = ' ORDER BY '.$column.' DESC';
					break;
		}	
		return;
	}
	
	function setCursorColumns($columns=null){
		if(is_null($columns)){return;}
		
		if(is_array($columns)){
				$tmp = array();
				foreach($columns as $column){
					if(isset($this->fields[$column])&&($column!=$this->id)){
						$tmp[]=$column;
					}
				}
				
				if(empty($tmp)){return;}
				
				$this->cursorColumns = $this->id.(!is_null($columns)?','.implode(",",array_unique($tmp)):'');
		}
	}
	
	function getCursor(){
		$result = $this->db_link->query($this->buildQuery());		
		if($this->db_link->error){
			throw new Exception('MySQL Error:'.$this->db_link->error.' Query:'.$this->buildQuery());
		}
		return $result;
	}
	
	function setLimit($limit=array()){
		$this->limit = $limit;
	}
	
	protected function buildQuery(){
		return 'SELECT '
					.(is_null($this->cursorColumns)?'*':$this->cursorColumns)
					.' FROM '
					.$this->name
					.$this->buildWhere()
					.(is_null($this->order)?'':' '.$this->order)
					.(empty($this->limit)?'':' LIMIT '.implode(",",$this->limit));
					
	}
		
	protected function buildWhere(){
		return (empty($this->filters)?'':' WHERE '.implode(" AND ",$this->filters));
	}
	function getFirstRowByFilter(){
		$this->init();
		$this->setLimit(array(1));
		$result = $this->db_link->query($this->buildQuery());
		$row = $result->fetch_assoc();
		if(is_null($row)){return false;};
		foreach($this->fields as $field){
			$this->setFieldValue($field['Name'],$row[$field['Name']]);
		};
		return true;
	}
	function getFirstRow(){
		return $this->getFirstRowByFilter();
	}
	function getCount(){
		$query = 'SELECT COUNT(*) as total FROM '
				.$this->name
				.$this->buildWhere();
		$result = $this->db_link->query($query);
		$row = $result->fetch_assoc();
		return is_null($row['total'])?0:$row['total'];
	}
	
	function getMinId(){
		$query = 'SELECT MIN(id) as minid FROM '
				.$this->name
				.$this->buildWhere();
		$result = $this->db_link->query($query);
		$row = $result->fetch_assoc();
		return is_null($row['minid'])?0:$row['minid'];
	}
	
	function copyFieldValuesFrom($copyFrom){
		$className = 'Table';
		if($copyFrom instanceof $className){
			$arrayFieldsCopyFrom = $copyFrom->fields();
			foreach($this->fields as $field){
				if(isset($arrayFieldsCopyFrom[$field['Name']])&&$field['Name']!=$this->id){
					$this->setFieldValue($field['Name'],$arrayFieldsCopyFrom[$field['Name']]['Value']);
				}
			}
		}elseif(is_array($copyFrom)){
			foreach($this->fields as $field){
				if(isset($copyFrom[$field['Name']])&&$field['Name']!=$this->id){
					$this->setFieldValue($field['Name'],$copyFrom[$field['Name']]);
				}
			}
		}
	}
	
	function copyAllFieldValuesFrom($copyFrom){
		$className = 'Table';
		if($copyFrom instanceof $className){
			$arrayFieldsCopyFrom = $copyFrom->fields();
			foreach($this->fields as $field){
				if(isset($arrayFieldsCopyFrom[$field['Name']])){
					$this->setFieldValue($field['Name'],$arrayFieldsCopyFrom[$field['Name']]['Value']);
				}
			}
		}elseif(is_array($copyFrom)){
			foreach($this->fields as $field){
				if(isset($copyFrom[$field['Name']])){
					$this->setFieldValue($field['Name'],$copyFrom[$field['Name']]['Value']);
				}
			}
		}
	}
	
	 function getGroupConcat($fieldName=null){
		$fieldName = is_null($fieldName)?$this->id:$this->secureValue($fieldName);
		$query = 'SELECT DISTINCT '.$fieldName.' FROM '
				.$this->name
				.$this->buildWhere();
		$result = $this->db_link->query($query);
		$row = $result->fetch_all();
		return implode(',',array_filter(array_column($row,0)));
	} 
	
	function getCursorAsArray(){
		$result = $this->getCursor();
		if(is_null($result)){return null;}
		$records = array();
		while($row=$result->fetch_assoc()){
			$records[]=$row;
		}
		return $records;
	}
	
	protected function suitArrayForQuery($arr){
		$inputArray = $arr;
		
		foreach($this->fields as $field){
			if(isset($inputArray[$field['Name']])){
				$inputArray[$field['Name']]=$field['Quotes'].$inputArray[$field['Name']].$field['Quotes'];
			}
			if(is_null($inputArray[$field['Name']])){$inputArray[$field['Name']]='NULL';}
		}
		return $inputArray;
	}
	
	function getRecordAsArray(){
		$record = array();
		foreach($this->fields as $field){
			$record[$field['Name']]=$field['Value'];
		}
		return $record;
	}
	
	function fetchRecord(){
		return $this->getRecordAsArray();
	}
	
	function getOldRecordAsArray(){
		$record = array();
		foreach($this->fields as $field){
			$record[$field['Name']]=$field['OldValue'];
		}
		return $record;
	}
	function fetchOldRecord(){
		return $this->getOldRecordAsArray();
	}
	
	function fetchCursor(){
		return $this->getCursorAsArray();
	}
	
	function getCursorAsHashMap(){
			$result = $this->getCursor();
			if(is_null($result)){return null;}
			$records = array();
			while($row=$result->fetch_assoc()){
				$records[$row[$this->id()]]=$row;
			}
			return $records;
	}
	
	function fetchHashMap(){
		return $this->getCursorAsHashMap();
	}
	
	protected function getTable($tableName){
		try{
			if(!is_null($this->tF)){
				return $this->tF->buildTable($tableName, $this->db_link, $this->tF);
			}else{return null;}	
		} catch (Exception $e){
			throw new Exception('Error getting table '.$tableName.' in function getTable in table class '.get_class($this).' : '.$e->getMessage());
		} 
	}
}

?>