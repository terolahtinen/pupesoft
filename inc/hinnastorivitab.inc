<?php

function hinnastoriviotsikot() {
  global $saatavuus;

  $out  = t('tuoteno')."\t";      // tuotenumero
  $out .= t('nimitys')."\t";      // tuotteen nimitys
  $out .= t('eankoodi')."\t";      // tuotteen eankoodi alennusryhm� kahteen merkkiin asti zeropaddattuna
  $out .= t('yksikko')."\t";      // tuotteen yksikko
  $out .= t('aleryhma')."\t";     // tuotteen aleryhm�
  $out .= t('hinta')."\t";         // tuotteen luettelohint
  $out .= t('tuotemerkki')."\t";    // tuotemerkki
  $out .= t('osasto')."\t";         // tuotteen osasto
  $out .= t('try')."\t";        // tuoteryhm�
  $out .= t('korvaava tuote')."\t";  // korvaava tuote
  if ($saatavuus == 'kylla') {
    $out .= t('onko saatavilla')."\t";//saatavuus
  }
  $out .= "\n";

  return $out;
}

function hinnastorivi($tuote, $laskurow) {
  global $kukarow, $yhtiorow, $saatavuus;


  //korvaavat tuotteet
  $koti     = '';
  $edellinen   = '';
  $seuraava   = '';
  $korvaavat  = '';

  if ($tuote["id"] > 0) {
    $query = "SELECT * from korvaavat where id='$tuote[id]' and yhtio='$kukarow[yhtio]' order by jarjestys, tuoteno";
    $korvaresult = pupe_query($query);

    $lask = 0;
    while ($korvarow = mysql_fetch_array($korvaresult)) {
      $korvaavat[$lask] = $korvarow["tuoteno"];
      if ($korvarow["tuoteno"] == $tuote["tuoteno"]) {
        $koti = $lask;
      }
      $lask++;
    }
    //t�ss� listan viimeinen indeksi
    $lask--;

    //edellinen ja seuraava korvaava
    if ($koti == 0) {
      $edellinen  = '';
      $seuraava  = $korvaavat[$koti+1];
    }
    elseif ($koti == $lask) {
      $edellinen  = $korvaavat[$koti-1];
      $seuraava  = '';
    }
    else {
      $edellinen  = $korvaavat[$koti-1];
      $seuraava  = $korvaavat[$koti+1];
    }
  }

  // Katsotaan, mist� l�ytyy enari
  if ($tuote["eankoodi"] == '') {
    $query = "SELECT *
              FROM tuotteen_toimittajat
              WHERE yhtio = '$kukarow[yhtio]'
              AND tuoteno = '$tuote[tuoteno]'
              ORDER BY if (jarjestys = 0, 9999, jarjestys) limit 1";
    $tuotetoim_res = pupe_query($query);
    $tuotetoimrow = mysql_fetch_assoc($tuotetoim_res);
    $tuote["eankoodi"] = $tuotetoimrow["viivakoodi"];
  }
    
  $myyntihinta = tuotteen_myyntihinta($laskurow, $tuote, 1, "ei");

  // tehd��n tietuetta
  $out = $tuote['tuoteno']."\t";    // tuotenumero
  $out .= t_tuotteen_avainsanat($tuote, 'nimitys')."\t";    // tuotteen nimitys
  $out .= $tuote['eankoodi']."\t";  // tuotteen eankoodi alennusryhm� kahteen merkkiin asti zeropaddattuna
  $out .= t_avainsana("Y", "", "and avainsana.selite='$tuote[yksikko]'", "", "", "selite")."\t";    // tuotteen yksikko
  $out .= $tuote['aleryhma']."\t";   // tuotteen aleryhm�
  $out .= $myyntihinta."\t";         // tuotteen luettelohint
  $out .= $tuote['tuotemerkki']."\t";  // tuotemerkki
  $out .= $tuote['osasto']."\t";       // tuotteen osasto
  $out .= $tuote['try']."\t";      // tuoteryhm�
  $out .= $seuraava;          // korvaava tuote
  if ($saatavuus == 'kylla') {
    $out .= "\t" . hae_saatavuus($laskurow, $tuote['tuoteno']);      // saatavuus
  }
  $out .= "\n";

  return $out;
}

function hae_saatavuus($laskurow, $tuoteno) {
  global $kukarow, $yhtiorow;

  list($saldo, $hyllyssa, $myytavissa) = saldo_myytavissa($tuoteno, "", 0, "", "", "", "", "", $laskurow["toim_maa"], '');

  if ($myytavissa > 0) {
    return t("Kyll�");
  }
  else {
    //HUOM! palauttaa vain yhden rivin
    $tulossa_query = "SELECT min(toimaika) paivamaara
                      FROM tilausrivi
                      WHERE yhtio  = '{$kukarow[yhtio]}'
                      AND tuoteno  = '{$tuoteno}'
                      AND varattu  > 0
                      AND tyyppi   = 'O'
                      AND toimaika >= curdate()";
    $tulossa_result = pupe_query($tulossa_query);
    $tulossa_row = mysql_fetch_assoc($tulossa_result);

    if (empty($tulossa_row['paivamaara'])) {
      return t("Ei");
    }
    else {
      return t("Tulossa") . ' ' . $tulossa_row['paivamaara'];
    }
  }
}
