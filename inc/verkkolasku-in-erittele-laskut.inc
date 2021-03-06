<?php
function erittele_laskut($file) {

  global $laskut, $origlaskut;

  $luotiinlaskuja = 0;
  $type = "";
  $boob = "";
  $soap = "";
  $ebid = "";

  $poistettavat = array('/', '.', ':'); //Merkit, jotka poistetaan filenimest�

  if (!PUPE_UNICODE) {
    $xmlencoding = "ISO-8859-15";
  }
  else {
    $xmlencoding = "UTF-8";
  }

  if (stripos(file_get_contents($file), "Finvoice") !== FALSE) {
    foreach (file($file) as $ln) {

      $ln = trim($ln); // varmistaan ett� ei j�� roskia

      //Haetaan Finvoicen tunnusmerkkej�
      if (preg_match("/\<SOAP\-ENV\:Envelope.*\>/i", $ln) > 0) {
        $type = "SOAP";
      }

      if (preg_match("/\<Finvoice.*?Version\=\"([1-2]\.[0-3]{1,2})\".*?\>/i", $ln, $lnversion) > 0) {
        $type        = "FINVOICE";
        $finvoiceversion = (float) $lnversion[1];

        $ln = substr($ln, stripos($ln, "<Finvoice"));

        // Lis�t��n varmasti oikeat DOCTYPE m��ritys
        // T�m� siksi ett� haluamme k�ytt�� paikallista kuvausta eik� ladata kuvausta verkosta!
        if ($finvoiceversion >= 2) {
          $ln = "<?xml version=\"1.0\" encoding=\"$xmlencoding\"?>\n<?xml-stylesheet type=\"text/xsl\" href=\"datain/Finvoice_2.0.xsl\"?>\n<Finvoice Version=\"$lnversion[1]\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xsi:noNamespaceSchemaLocation=\"datain/Finvoice_2.0.xsd\">";
        }
        elseif ($finvoiceversion == 1.3) {
          $ln = "<?xml version=\"1.0\" encoding=\"$xmlencoding\"?>\n<!DOCTYPE Finvoice SYSTEM \"datain/Finvoice_1.3.dtd\">\n<?xml-stylesheet type=\"text/xsl\" href=\"datain/Finvoice_1.3.xsl\"?>\n".$ln;
        }
        else {
          $ln = "<?xml version=\"1.0\" encoding=\"$xmlencoding\"?>\n<!DOCTYPE Finvoice SYSTEM \"datain/Finvoice.dtd\">\n<?xml-stylesheet type=\"text/xsl\" href=\"datain/Finvoice.xsl\"?>\n".$ln;
        }
      }

      //  Meill� oli SOAP kehys, t��lt� me saadaan EBID sender-messageid
      if (isset($type) and $type == "SOAP" and preg_match("/\<eb\:MessageId\>(.*?)\<\/eb\:MessageId\>/i", $ln, $messageid) > 0) {
        $ebid = $messageid[1];
        $ebid = str_replace($poistettavat, "_", $ebid);
      }

      if ($type == "SOAP") $soap .= $ln; // T�ss� soap kehys
      if ($type == "FINVOICE") $boob .= $ln."\n"; // Rakennetaan varsinainen finvoice tiedosto

      //   Tiedostosta l�ytyi finvoiceaineisto
      if ($type == "FINVOICE" and stripos($ln, "</Finvoice>") !== FALSE) {

        // Oteaan SOAP-kehyksest� Receiver PartyId koska se ei nyt aina tulekaan niin kauniisti Finvoicessa, esim jos toimittajalla on huono asiakasrekisteri
        if ($soap != "" and preg_match("/\<eb\:To\>\<eb\:PartyId\>(.*?)\<\/eb\:PartyId\>\<eb\:Role\>Receiver\<\/eb\:Role\>\<\/eb\:To\>/i", $soap, $receiverparty) > 0) {

          // Jos tieto l�ytyy
          if (trim($receiverparty[1]) != "") {
            // L�ytyyk� BuyerOrganisationUnitNumber-segmentti
            if (preg_match("/\<BuyerOrganisationUnitNumber\>.*?\<\/BuyerOrganisationUnitNumber\>/i", $boob, $buyermatch)) {
              // Jos l�ytyy niin muokataan
              $boob = str_replace($buyermatch[0], "<BuyerOrganisationUnitNumber>".$receiverparty[1]."</BuyerOrganisationUnitNumber>", $boob);
            }
            else {
              // Muuten lis�t��n
              $boob = str_replace("</BuyerPartyDetails>", "</BuyerPartyDetails>\n<BuyerOrganisationUnitNumber>".$receiverparty[1]."</BuyerOrganisationUnitNumber>", $boob);
            }
          }
        }

        if ($ebid == "") {
          $ebid = "gen".substr(md5(rand()), 0, 20);
        }

        $path_parts = pathinfo($file);

        // Siivotaan vanha nimi, ettei nimi mee liian pitk�ksi...
        $path_parts["filename"] = preg_replace("/(finvoice\-)/", "", $path_parts["filename"]);
        $path_parts["filename"] = preg_replace("/(\-gen[a-z0-9]{20,20})/", "", $path_parts["filename"]);

        $filenimi = "finvoice-{$path_parts["filename"]}-$ebid.xml";
        $save = file_put_contents($laskut."/".$filenimi, $boob);

        if ($save !== FALSE) {
          $luotiinlaskuja++;
        }
        else {
          echo "Laskutiedoston tallennuksessa tapahtui virhe!\n";
        }

        // Haetaan taas uutta aineistoa
        $type = "";
        $boob = "";
        $soap = "";
        $ebid = "";
      }
    }
  }
  else {
    // Muut kuin finvoicelaskut
    $xmldata = file_get_contents($file);

    // Varmistetaan, ett� <xml>-tagi l�ytyy aineistosta, jos ei niin lis�t��n
    if (!preg_match("/\<\?xml[^\>]*/i", $xmldata)) {
      $xmldata = "<?xml version=\"1.0\" encoding=\"$xmlencoding\"?>\n".$xmldata;
    }
    elseif (!PUPE_UNICODE) {
      // XML-tiedosto on konvertoitu ISO-8859-15-merkist��n, joten varmistetaan, ett�
      // <xml>-tagissa kerrotaan t�m�
      $xmldata = preg_replace("/\<\?xml[^\>]*/i", "<?xml version=\"1.0\" encoding=\"$xmlencoding\"?", $xmldata);
    }
    else {
      // XML-tiedosto on konvertoitu UTF-8-merkist��n, joten varmistetaan, ett�
      // <xml>-tagissa kerrotaan t�m�
      $xmldata = preg_replace("/\<\?xml[^\>]*/i", "<?xml version=\"1.0\" encoding=\"$xmlencoding\"?", $xmldata);
    }

    $xml = @simplexml_load_string($xmldata);

    if ($xml !== FALSE) {

      // Teccom laskuaineisto
      if (isset($xml->Document) and isset($xml->Document->attributes()->Type) and $xml->Document->attributes()->Type == "Invoice") {

        // Laitetaan teccomifile ensin talteen sellaisenaan
        $path_parts = pathinfo($file);

        // Siivotaan vanha nimi, ettei nimi mee liian pitk�ksi...
        $path_parts["basename"] = preg_replace("/(teccominvoice_)/", "", $path_parts["basename"]);

        $filenimi = "teccominvoice_{$path_parts['basename']}";
        copy($file, $origlaskut."/".$filenimi);

        // poistetaan nonprintable merkit
        $xmldata = preg_replace("/[^\x20-\xFF\n]/", "", $xmldata);

        // T�m� siksi ett� haluamme k�ytt�� paikallista kuvausta eik� ladata kuvausta verkosta!
        $xmldata = preg_replace("/^.*?<Invoice>/ms", "<?xml version=\"1.0\" encoding=\"$xmlencoding\"?>\n<?xml-stylesheet type=\"text/xsl\" href=\"datain/teccom-invoice.xsl\"?>\n<Invoice>", $xmldata, 1);

        $save = file_put_contents($file, $xmldata);
        $path_parts = pathinfo($file);

        // Siivotaan vanha nimi, ettei nimi mee liian pitk�ksi...
        $path_parts["basename"] = preg_replace("/(teccominvoice_)/", "", $path_parts["basename"]);

        rename($file, "{$laskut}/teccominvoice_{$path_parts['basename']}");
      }
    }
  }

  return $luotiinlaskuja;
}
