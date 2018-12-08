<?php

use app\models\Project;
use yii\db\Schema;
use yii\db\Migration;

class m181208_074202_alter_table_project_rsync extends Migration
{
    public function up()
    {

        $this->addColumn(Project::tableName(), 'rsync', Schema::TYPE_SMALLINT . '(1) NOT NULL DEFAULT 0 COMMENT "是否开启rsync模式发布代码"');

    }

    public function down()
    {

        $this->dropColumn(Project::tableName(), 'rsync');
        echo "m181208_074202_alter_table_project_rsync cannot be reverted.\n";

        return true;
    }

    /*
    // Use safeUp/safeDown to run migration code within a transaction
    public function safeUp()
    {
    }

    public function safeDown()
    {
    }
    */
}
