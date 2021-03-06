<?php

$query = "SELECT *
          FROM lasku
          WHERE tunnus = '$tunnus'
          AND yhtio ='$kukarow[yhtio]'";
$lresult = pupe_query($query);

if (mysql_num_rows($lresult) == 0) {
  echo "<b>".t("Lasku")." '$tunnus' ".t("katosi")."</b><br>";
  exit;
}

$laskurow = mysql_fetch_assoc($lresult);

$query = "SELECT *
          FROM tiliointi
          WHERE tunnus = '$isa'
          AND ltunnus  = '$tunnus'
          AND yhtio    = '$kukarow[yhtio]'";
$tresult = pupe_query($query);

if (mysql_num_rows($tresult) == 0) {
  echo "<b>".t("Tiliointi")." '$isa' ".t("katosi")."</b><br>";
  exit;
}

$tiliointirow = mysql_fetch_assoc($tresult);

if ($tiliointirow['vero'] != 0) {
  echo "<br>";
  echo "<font class='error'>".t("ALV:n sis�lt�v�� rivi� ei voida jaksottaa")."!</font><br>";
  echo "<br>";
  $tee = 'E';
}

// Jaksotetaan tehty� rivi�
if ($tee == 'U') {

  echo "<br>";
  echo "<font class='head'>".t("Tili�innin jaksotus")."</font><hr>";

  echo "<font class='message'>".t("Jaksotettava summa")." $tiliointirow[summa] $yhtiorow[valkoodi]</font><br><br>";

  echo "<font class='message'>".t("Valitse jaksotuksen p�iv�t").":</font><br><br>";

  echo "<form method = 'post'>";
  echo "<input type = 'hidden' name = 'tunnus' value = '$tunnus'>";
  echo "<input type = 'hidden' name = 'isa' value = '$isa'>";
  echo "<input type = 'hidden' name = 'tee' value = 'J'>";
  echo "<table>";

  echo "<tr>";
  echo "<td><input type='checkbox' name='kausi[]' value='1'> ".tv1dateconv($tiliointirow["tapvm"])."</td>";

  $kk = substr($tiliointirow['tapvm'], 5, 2); // T�m� on kausi
  $vv = substr($tiliointirow['tapvm'], 0, 4); // T�m� on vuosi

  for ($i = 2; $i <= 30; $i++) {

    echo "<td>";
    echo "<input type='checkbox' name='kausi[]' value='$i'> ";
    echo date("d.m.Y", mktime(0, 0, 0, $kk+$i-1, 1, $vv));
    echo "</td>";

    // Tehd��n v�h�n sarakkeita
    if ($i % 6 == 0) {
      echo "</tr><tr>";
    }
  }

  echo "</tr>";
  echo "</table>";

  echo "<br>";
  echo "<input type='submit' value='".t("Jaksota")."'>";

  echo "</form>";
}
elseif (count($kausi) > 0) {

  $pkk = substr($tiliointirow['tapvm'], 5, 2); // T�m� on kausi
  $pvv = substr($tiliointirow['tapvm'], 0, 4); // T�m� on vuosi
  $kpl = count($kausi);

  $summa = round($tiliointirow['summa']/$kpl, 2);

  $tsumma = 0;

  $eka = 0; // T�ll� katsotaan ollaanko ensimm�isell� kierroksella

  foreach ($kausi as $mille) {

    // mik� on jaksotuksen vastatili
    if ($laskurow["tila"] == "U") {
      // jos kyseess� on myyntilasku niin
      $jaksotilino = $yhtiorow['siirtovelka'];
    }
    else {
      // muissa tapauksissa
      $jaksotilino = $yhtiorow['siirtosaamiset'];
    }

    // Mit� tapahtuu alkuper�iselle tili�innille
    if ($ok == 0) {

      $tapvm = $tiliointirow['tapvm'];

      // Varsinainen tili�inti j�� voimaan
      if ($mille == 1) {
        $asumma = $summa;
      }
      else {
        $asumma = 0;
      }

      $query = "INSERT INTO tiliointi SET
                yhtio    = '$kukarow[yhtio]',
                ltunnus  = '$tiliointirow[ltunnus]',
                tilino   = '$tiliointirow[tilino]',
                kustp    = '$tiliointirow[kustp]',
                kohde    = '$tiliointirow[kohde]',
                projekti = '$tiliointirow[projekti]',
                tapvm    = '$tapvm',
                summa    = '$asumma',
                vero     = 0,
                selite   = 'Jaksotus $tiliointirow[selite]',
                lukko    = '',
                laatija  = '$kukarow[kuka]',
                laadittu = now()";
      $result = pupe_query($query);
      $jisa = mysql_insert_id($GLOBALS["masterlink"]);
      $viimeinen1 = $jisa;

      // Tehd��n vastaava siirtosaaminen
      $ssumma = $tiliointirow['summa'] - $asumma;
      $query = "INSERT INTO tiliointi SET
                yhtio     = '$kukarow[yhtio]',
                ltunnus   = '$tiliointirow[ltunnus]',
                tilino    = '$jaksotilino',
                kustp     = '$tiliointirow[kustp]',
                kohde     = '$tiliointirow[kohde]',
                projekti  = '$tiliointirow[projekti]',
                tapvm     = '$tapvm',
                summa     = $ssumma,
                vero      = 0,
                selite    = 'Jaksotus $tiliointirow[selite]',
                lukko     = '1',
                aputunnus = '$jisa',
                laatija   = '$kukarow[kuka]',
                laadittu  = now()";
      $result = pupe_query($query);
      $viimeinen2 = mysql_insert_id($GLOBALS["masterlink"]);

      $ok = 1; // Ensimm�inen on k�sitelty
      $tsumma += $asumma; // Kuinka paljon olemme tili�inneet
    }

    // Emme k�sittele varsinaista tapahtumaa
    if ($mille != 1) {

      // Jossain on jotain m�t��, ei is�-tili�inti�!
      if ($jisa == 0) {
        echo "jisa = 0, system error<br>";
        exit;
      }

      $pkk = substr($tiliointirow['tapvm'], 5, 2); // T�m� on kausi
      $pvv = substr($tiliointirow['tapvm'], 0, 4); // T�m� on vuosi
      $tapvm = date("Y-m-d", mktime(0, 0, 0, $pkk+$mille-1, 1, $pvv));

      $query = "INSERT INTO tiliointi SET
                yhtio     = '$kukarow[yhtio]',
                ltunnus   = '$tiliointirow[ltunnus]',
                tilino    = '$tiliointirow[tilino]',
                kustp     = '$tiliointirow[kustp]',
                kohde     = '$tiliointirow[kohde]',
                projekti  = '$tiliointirow[projekti]',
                tapvm     = '$tapvm',
                summa     = '$summa',
                vero      = 0,
                selite    = 'Jaksotus $tiliointirow[selite]',
                lukko     = '1',
                aputunnus = '$jisa',
                laatija   = '$kukarow[kuka]',
                laadittu  = now()";
      $result = pupe_query($query);
      $viimeinen1 = mysql_insert_id($GLOBALS["masterlink"]);

      $query = "INSERT INTO tiliointi SET
                yhtio     = '$kukarow[yhtio]',
                ltunnus   = '$tiliointirow[ltunnus]',
                tilino    = '$jaksotilino',
                kustp     = '$tiliointirow[kustp]',
                kohde     = '$tiliointirow[kohde]',
                projekti  = '$tiliointirow[projekti]',
                tapvm     = '$tapvm',
                summa     = $summa * -1,
                vero      = 0,
                selite    = 'Jaksotus $tiliointirow[selite]',
                lukko     = '1',
                aputunnus = '$jisa',
                laatija   = '$kukarow[kuka]',
                laadittu  = now()";
      $result = pupe_query($query);
      $viimeinen2 = mysql_insert_id($GLOBALS["masterlink"]);

      $tsumma += $summa;
    }
  }

  // Jossain on jotain m�t��, ei viimeist� tili�inti�!
  if ($viimeinen1 == 0 or $viimeinen2 == 0) {
    echo "viimeinen1 tai viimeinen2 = 0 system error<br>";
    exit;
  }

  // Jossain on jotain m�t��, perustili�inti katosi!
  if ($isa == 0) {
    echo "isa = 0 system error<br>";
    exit;
  }

  if ($tsumma != $tiliointirow['summa']) {
    $tsumma -= $tiliointirow['summa'];
    $tsumma = round($tsumma, 2);

    $query = "UPDATE tiliointi SET summa = summa - $tsumma WHERE tunnus = '$viimeinen1' and yhtio = '$kukarow[yhtio]'";
    $result = pupe_query($query);

    $query = "UPDATE tiliointi SET summa = summa + $tsumma WHERE tunnus = '$viimeinen2' and yhtio = '$kukarow[yhtio]'";
    $result = pupe_query($query);
  }

  // Yliviivataan varsinainen tiliointi
  $query = "UPDATE tiliointi SET korjattu = '$kukarow[kuka]', korjausaika = now() WHERE tunnus = '$isa' and yhtio = '$kukarow[yhtio]'";
  $result = pupe_query($query);
  $tee = 'E';
}
elseif ($tee != "E") {
  echo "<font class='error'>".t("Et valinnut mit��n jaksotettavaa")."!</font><br>";
  echo "<br>";
  $tee = 'E';
}
