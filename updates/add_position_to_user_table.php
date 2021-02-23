<?php namespace StudioAzura\BackendUserPlus\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class AddPositionToUserTable extends Migration
{

    public function up()
    {
        Schema::table('backend_users', function ($table) {
            $table->string('position')->nullable();
        });
    }

    public function down()
    {
        Schema::table('backend_users', function ($table) {
            $table->dropColumn('position');
        });
    }

}
