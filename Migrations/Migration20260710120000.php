<?php

namespace Plugin\endereco_jtl5_client\Migrations;

use JTL\Plugin\Migration;
use JTL\Update\IMigration;

class Migration20260710120000 extends Migration implements IMigration
{
    public function up()
    {
        // Metadata written by JS SDK 1.10 carries no address fingerprint and
        // cannot prove whether optional fields (subdivision, additional info)
        // took part in the validation. Purge it once with the upgrade to
        // plugin 1.4.0 (SDK 1.14.3) so addresses are revalidated on their next
        // eligible workflow. The table schema stays unchanged.
        $this->execute("DELETE FROM `xplugin_endereco_jtl5_client_tams`");
    }

    public function down()
    {
        // Intentionally a no-op: deleted validation metadata cannot be
        // reconstructed.
    }
}
