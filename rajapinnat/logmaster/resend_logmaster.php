<?php

//* T�m� skripti k�ytt�� slave-tietokantapalvelinta *//
$useslave = 1;

require "../../inc/parametrit.inc";
require "rajapinnat/logmaster/logmaster-functions.php";

$tee = empty($tee) ? '' : $tee;

echo "<font class='head'>".t("Uudelleenl�het� LogMaster-ker�yssanoma")."</font><hr>";

if (!LOGMASTER_RAJAPINTA or !in_array($yhtiorow['ulkoinen_jarjestelma'], array('', 'K'))) {
  echo t("Ker�tt�vien tilauksien l�hett�minen estetty yhti�tasolla")."!<br>";
  $tee = '';
}

if ($tee == "laheta" and $tilaukset != "") {

  $tilaukset = str_replace(array("\r", "\n", " "), "", $tilaukset);
  $tilaukset = explode(",", $tilaukset);
  $tilaukset = array_filter($tilaukset, 'is_numeric');
  $tilaukset = implode(",", $tilaukset);

  # Tilaus pit�� olla jo l�hetetty ulkoiseen varastoon, jotta se voidaan l�hett�� uudestaan
  $query = "SELECT DISTINCT lasku.tunnus
            FROM lasku
            JOIN varastopaikat ON (lasku.yhtio = varastopaikat.yhtio
              AND lasku.varasto = varastopaikat.tunnus
              AND varastopaikat.ulkoinen_jarjestelma IN ('L','P')
            )
            WHERE lasku.yhtio = '{$kukarow['yhtio']}'
            AND lasku.lahetetty_ulkoiseen_varastoon > 0
            AND lasku.tila IN ('L','G')
            AND lasku.tunnus IN ({$tilaukset})";
  $res = pupe_query($query);

  if (mysql_num_rows($res) > 0) {
    echo t("Uudelleenl�hetet��n LogMaster-ker�yssanoma").": {$tilaukset}<br>";

    while ($laskurow = mysql_fetch_assoc($res)) {
      $filename = logmaster_outbounddelivery($laskurow['tunnus']);

      if ($filename === false) {
        echo t("Tilauksen %d sanoman luonti ep�onnistui", '', $laskurow['tunnus'])."<br>";
        continue;
      }

      $palautus = logmaster_send_file($filename);

      if ($palautus == 0) {
        pupesoft_log('logmaster_outbound_delivery', "Siirretiin tilaus {$laskurow['tunnus']}.");
        echo t("Siirretiin tilaus %d", '', $laskurow['tunnus'])."<br>";
      }
      else {
        pupesoft_log('logmaster_outbound_delivery', "Tilauksen {$laskurow['tunnus']} siirto ep�onnistui.");
        echo t("Tilauksen %d siirto ep�onnistui", '', $laskurow['tunnus'])."<br>";
      }
    }
  }
  else {
    echo "<font class='error'>".t("Tilauksia ei l�ytynyt").": {$tilaukset}!</font><br>";
  }
}

echo "<br><br><font class='message'>".t("Anna tilausnumerot pilkulla eroteltuna")."</font><br>";
echo "<form method='post'>";
echo "<input type='hidden' name='tee' value='laheta'>";
echo "<textarea name='tilaukset' rows='10' cols='60'></textarea>";
echo "<br><input type='submit' value='".t("L�het� ker�yssanomat")."'>";
echo "</form>";

require "inc/footer.inc";