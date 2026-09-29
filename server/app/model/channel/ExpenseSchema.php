<?php
namespace app\model\channel;
final class ExpenseSchema
{
 public static function creationStatements(): array { return ["CREATE TABLE IF NOT EXISTS base_expense_item (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, expense_id BIGINT UNSIGNED NOT NULL, project VARCHAR(180) NOT NULL, amount DECIMAL(12,2) NOT NULL, sort INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, KEY idx_expense(expense_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"]; }
 public static function apply(): void { $c=TableRecord::connection(); $defs=self::columnDefinitions(); foreach($defs as $n=>$d) if(!$c->getSchemaBuilder()->hasColumn('base_expense',$n))$c->statement("ALTER TABLE base_expense ADD COLUMN `$n` $d"); foreach(self::creationStatements() as $sql)$c->statement($sql); }
 public static function applyToPdo(\PDO $pdo): void { foreach(self::columnDefinitions() as $n=>$d) ensureColumn($pdo,'base_expense',$n,"ALTER TABLE `base_expense` ADD COLUMN `$n` $d"); foreach(self::creationStatements() as $sql)$pdo->exec($sql); }
 public static function columnDefinitions(): array { return ['term_id'=>'BIGINT UNSIGNED NULL','semester'=>'VARCHAR(80) NULL','base_type'=>'VARCHAR(80) NULL','base_category'=>'VARCHAR(120) NULL','expense_type'=>'VARCHAR(80) NULL','total_amount'=>'DECIMAL(12,2) NULL','amount_upper'=>'VARCHAR(180) NULL','attachment_ids'=>'TEXT NULL','remark'=>'TEXT NULL','workflow_status'=>"VARCHAR(30) NOT NULL DEFAULT 'draft'"]; }
}
