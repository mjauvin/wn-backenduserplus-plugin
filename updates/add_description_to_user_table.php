<?php namespace StudioAzura\BackendUserPlus\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class AddDescriptionToUserTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('backend_users', 'description')) {
            Schema::table('backend_users', function ($table) {
                $table->text('description')->nullable();
            });
        }
    }

    public function down()
    {
        Schema::table('backend_users', function ($table) {
            $table->dropColumn('description');
        });
    }
}
