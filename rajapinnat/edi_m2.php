<?php

class Edi {

  /**
   * Luo edi tilauksen
   *
   * @param array   $order   Tilauksen tiedot ja tilauserivit
   * @param array   $options Tarvittavat parametrit
   * @return string           Luodun tiedoston polku
   */


  static function create($order_object, $options) {
    $magento_api_ht_edi            = $options['edi_polku'];
    $ovt_tunnus                    = $options['ovt_tunnus'];
    $pupesoft_tilaustyyppi         = $options['tilaustyyppi'];
    $magento_maksuehto_ohjaus      = $options['maksuehto_ohjaus'];
    $verkkokauppa_asiakasnro       = $options['asiakasnro'];
    $rahtikulu_tuoteno             = $options['rahtikulu_tuoteno'];
    $rahtikulu_nimitys             = $options['rahtikulu_nimitys'];
    $verkkokauppa_erikoiskasittely = $options['erikoiskasittely'];

    $order = $order_object->result;

    if (!empty($order->billingAddress->vatId)) {
      $verkkokauppa_asiakasnro = $order->billingAddress->vatId;
    }

    if (empty($magento_api_ht_edi) or empty($ovt_tunnus) or empty($pupesoft_tilaustyyppi)) {
      die("Parametrej� puuttuu\n");
    }

    if (empty($verkkokauppa_asiakasnro) or empty($rahtikulu_tuoteno) or empty($rahtikulu_nimitys)) {
      die("Parametrej� puuttuu\n");
    }

    if (!is_writable($magento_api_ht_edi)) {
      die("EDI -hakemistoon ei voida kirjoittaa\n");
    }

    // Tilauksella k�ytetyt lahjakortit ei saa vehent�� myynti pupen puolella
    //ei toimi viel?, placeholder arvot
    $giftcards = isset($order->items->giftcardItemOption) ? json_decode($order['webtex_giftcard']): null;

    if (!empty($giftcards)) {
      $giftcard_sum = 0;

      foreach ($giftcards as $giftcard_values) {
        foreach ($giftcard_values as $index => $value) {
          if (!stristr($index, 'classname')) {
            $giftcard_sum += $value;
          }
        }
      }

      $grand_total = $order['grand_total'] + $giftcard_sum;
    }
    else {
      $grand_total = $order->grandTotal;
    }

    $vaihtoehtoinen_ovt = '';
    $vaihtoehtoinen_asiakasnro = '';

    //Tarkistetaan onko t�m�n nimiselle verkkokaupalle asetettu erikoisk�sittelyj�
    if (isset($verkkokauppa_erikoiskasittely) and count($verkkokauppa_erikoiskasittely) > 0) {
      $edi_store = str_replace("\n", " ", $order->storeName);

      foreach ($verkkokauppa_erikoiskasittely as $verkkokauppaparametrit) {
        // Avaimet
        // 0 = Verkkokaupan nimi
        // 1 = Editilaus_tilaustyyppi
        // 2 = Tilaustyyppilisa
        // 3 = Myyjanumero
        // 4 = Vaihtoehtoinen ovttunnus OSTOTIL.OT_TOIMITTAJANRO -kentt��n EDI tiedostossa
        // 5 = Rahtivapaus, jos 'E', niin k�ytet��n asiakkaan 'rahtivapaa' -oletusta
        // 6 = Tyhjennet��nk� OSTOTIL.OT_MAKSETTU EDI tiedostossa (tyhj� ei, kaikki muut arvot kyll�)
        // 7 = Vaihtoehtoinen asiakasnro

        if (strpos($edi_store, $verkkokauppaparametrit[0]) !== false) {
          $vaihtoehtoinen_ovt = $verkkokauppaparametrit[4];
        }

        if (strpos($edi_store, $verkkokauppaparametrit[0]) !== false and !empty($verkkokauppaparametrit[7])) {
          $vaihtoehtoinen_asiakasnro = $verkkokauppaparametrit[7];
        }
      }
    }

    $valittu_ovt_tunnus = (!empty($vaihtoehtoinen_ovt)) ? $vaihtoehtoinen_ovt : $ovt_tunnus;
    $verkkokauppa_asiakasnro = (!empty($vaihtoehtoinen_asiakasnro)) ? $vaihtoehtoinen_asiakasnro : $verkkokauppa_asiakasnro;

    $maksuehto = strip_tags($order->payment->method);

    // Jos on asetettu maksuehtojen ohjaus, tarkistetaan korvataanko Magentosta tullut maksuehto
    if (isset($magento_maksuehto_ohjaus) and count($magento_maksuehto_ohjaus) > 0) {
      foreach ($magento_maksuehto_ohjaus as $key => $array) {
        if (in_array($maksuehto, $array) and !empty($key)) {
          $maksuehto = $key;
        }
      }
    }

    $store_name = str_replace("\n", " ", $order->storeName);
    $billingadress = str_replace("\n", ", ", $order->billingAddress->street->item);
    $shippingadress = str_replace("\n", ", ", $order->extensionAttributes->shippingAssignments->item->shipping->address->street->item);

    // Yritystilauksissa vaihdetaan yrityksen ja tilaajan nimi toisin p�in
    if (isset($order->billingAddress->company)) {
      $billing_company = $order->billingAddress->lastname." ".$order->billingAddress->firstname;
      $billing_contact = $order->billingAddress->company;

      $shipping_company = $order->extensionAttributes->shippingAssignments->item->shipping->address->lastname." ".$order->extensionAttributes->shippingAssignments->item->shipping->address->firstname;
      $shipping_contact = $order->extensionAttributes->shippingAssignments->item->shipping->address->company;
    }
    else {
      $billing_company = '';
      $billing_contact = $order->billingAddress->lastname." ".$order->billingAddress->firstname;

      if(isset($order->extensionAttributes->shippingAssignments->item->shipping->address->company)){
        $shipping_company = $order->extensionAttributes->shippingAssignments->item->shipping->address->company;
      }
      else {
        $shipping_company = '';
      }
      $shipping_contact = $order->extensionAttributes->shippingAssignments->item->shipping->address->lastname." ".$order->extensionAttributes->shippingAssignments->item->shipping->address->firstname;
    }

    $noutopistetunnus = '';
    $tunnisteosa = 'matkahuoltoNearbyParcel_';

    // Jos shipping_method sis�lt�� tunnisteosan ja sen per�ss� on numero niin otetaan talteen
    if (!isset($order->extensionAttributes->shippingAssignments->item->shipping->method) and strpos($order->extensionAttributes->shippingAssignments->item->shipping->method, $tunnisteosa) !== false) {
      $tunnistekoodi = str_replace($tunnisteosa, '', $order->extensionAttributes->shippingAssignments->item->shipping->method);
      $noutopistetunnus = is_numeric($tunnistekoodi) ? $tunnistekoodi : '';
    }

    // Noutopiste voi olla my�s katuosoitteen lopussa esim "Testitie 1 [#12345]"
    preg_match("/\[#([A-Za-z0-9]*)\]/", $shippingadress, $tunnistekoodi);
    if ($noutopistetunnus == '' and !empty($tunnistekoodi[1])) {
      $noutopistetunnus = $tunnistekoodi[1];
      $shippingadress = str_replace($tunnistekoodi[0], "", $shippingadress);
    }

    $tilausviite = '';
    $tilausnumero = '';
    $kohde = '';
    $toimaika = '';

    //DEBUG ei tarkastettu nimi: reference_number
    if (isset($order->reference_number)) {
      $tilausviite = str_replace("\n", " ", $order->reference_number);
    }

    if (isset($order->incrementId)) {
      $tilausnumero = str_replace("\n", " ", $order->incrementId);
    }

    //DEBUG ei tarkastettu nimi: target
    if (isset($order->target)) {
      $kohde = str_replace("\n", " ", $order->target);
    }

    //DEBUG ei tarkastettu nimi: delivery_time
    if (isset($order->delivery_time)) {
      $toimaika = str_replace("\n", " ", $order->delivery_time);
    }
    else {
      $toimaika = date("Y-m-d");
    }

    // tilauksen otsikko
    $edi_order  = "*IS from:721111720-1 to:IKH,ORDERS*id:{$order->incrementId} version:AFP-1.0 *MS\n";
    $edi_order .= "*MS {$order->incrementId}\n";
    $edi_order .= "*RS OSTOTIL\n";
    $edi_order .= "OSTOTIL.OT_NRO:{$order->incrementId}\n";
    $edi_order .= "OSTOTIL.OT_TOIMITTAJANRO:{$valittu_ovt_tunnus}\n";
    $edi_order .= "OSTOTIL.OT_TILAUSTYYPPI:{$pupesoft_tilaustyyppi}\n";
    $edi_order .= "OSTOTIL.VERKKOKAUPPA:{$store_name}\n";
    $edi_order .= "OSTOTIL.OT_VERKKOKAUPPA_ASIAKASNRO:{$order->customerId}\n"; //t�m� tulee suoraan Magentosta
    $edi_order .= "OSTOTIL.OT_VERKKOKAUPPA_TILAUSVIITE:{$tilausviite}\n";
    $edi_order .= "OSTOTIL.OT_VERKKOKAUPPA_TILAUSNUMERO:{$tilausnumero}\n";
    $edi_order .= "OSTOTIL.OT_VERKKOKAUPPA_KOHDE:{$kohde}\n";
    $edi_order .= "OSTOTIL.OT_TILAUSAIKA:{$toimaika}\n";
    $edi_order .= "OSTOTIL.OT_KASITTELIJA:\n";
    $edi_order .= "OSTOTIL.OT_TOIMITUSAIKA:{$toimaika}\n";
    $edi_order .= "OSTOTIL.OT_TOIMITUSTAPA:{$order->shippingDescription}\n";
    $edi_order .= "OSTOTIL.OT_TOIMITUSEHTO:\n";
    $edi_order .= "OSTOTIL.OT_MAKSETTU:{$order->status}\n";
    $edi_order .= "OSTOTIL.OT_MAKSUEHTO:{$maksuehto}\n";
    $edi_order .= "OSTOTIL.OT_VIITTEEMME:\n";
    $edi_order .= "OSTOTIL.OT_VIITTEENNE:\n";
    if(isset($order->customerNote)){
      $note = $order->customerNote;
    }
    else {
      $note = '';
    }
    $edi_order .= "OSTOTIL.OT_TILAUSVIESTI:{$note}\n";
    $edi_order .= "OSTOTIL.OT_VEROMAARA:{$order->taxAmount}\n";
    $edi_order .= "OSTOTIL.OT_SUMMA:{$grand_total}\n";
    $edi_order .= "OSTOTIL.OT_VALUUTTAKOODI:{$order->orderCurrencyCode}\n";
    $edi_order .= "OSTOTIL.OT_KLAUSUULI1:\n";
    $edi_order .= "OSTOTIL.OT_KLAUSUULI2:\n";
    $edi_order .= "OSTOTIL.OT_KULJETUSOHJE:\n";
    $edi_order .= "OSTOTIL.OT_LAHETYSTAPA:\n";
    $edi_order .= "OSTOTIL.OT_VAHVISTUS_FAKSILLA:\n";
    $edi_order .= "OSTOTIL.OT_FAKSI:\n";
    $edi_order .= "OSTOTIL.OT_ASIAKASNRO:{$verkkokauppa_asiakasnro}\n";
    $edi_order .= "OSTOTIL.OT_YRITYS:{$billing_company}\n";
    $edi_order .= "OSTOTIL.OT_YHTEYSHENKILO:{$billing_contact}\n";
    $edi_order .= "OSTOTIL.OT_KATUOSOITE:".$billingadress."\n";
    $edi_order .= "OSTOTIL.OT_POSTITOIMIPAIKKA:{$order->billingAddress->city}\n";
    $edi_order .= "OSTOTIL.OT_POSTINRO:{$order->billingAddress->postcode}\n";
    $edi_order .= "OSTOTIL.OT_YHTEYSHENKILONPUH:{$order->billingAddress->telephone}\n";
    if(isset($order->fax)){
      $fax = $order->fax;
    }
    else {
      $fax = '';
    }
    $edi_order .= "OSTOTIL.OT_YHTEYSHENKILONFAX:{$fax}\n";
    $edi_order .= "OSTOTIL.OT_MYYNTI_YRITYS:\n";
    $edi_order .= "OSTOTIL.OT_MYYNTI_KATUOSOITE:\n";
    $edi_order .= "OSTOTIL.OT_MYYNTI_POSTITOIMIPAIKKA:\n";
    $edi_order .= "OSTOTIL.OT_MYYNTI_POSTINRO:\n";
    $edi_order .= "OSTOTIL.OT_MYYNTI_MAAKOODI:\n";
    $edi_order .= "OSTOTIL.OT_MYYNTI_YHTEYSHENKILO:\n";
    $edi_order .= "OSTOTIL.OT_MYYNTI_YHTEYSHENKILONPUH:\n";
    $edi_order .= "OSTOTIL.OT_MYYNTI_YHTEYSHENKILONFAX:\n";
    $edi_order .= "OSTOTIL.OT_TOIMITUS_YRITYS:{$shipping_company}\n";
    $edi_order .= "OSTOTIL.OT_TOIMITUS_NIMI:{$shipping_contact}\n";
    $edi_order .= "OSTOTIL.OT_TOIMITUS_KATUOSOITE:".$shippingadress."\n";
    $edi_order .= "OSTOTIL.OT_TOIMITUS_POSTITOIMIPAIKKA:{$order->extensionAttributes->shippingAssignments->item->shipping->address->city}\n";
    $edi_order .= "OSTOTIL.OT_TOIMITUS_POSTINRO:{$order->extensionAttributes->shippingAssignments->item->shipping->address->postcode}\n";
    $edi_order .= "OSTOTIL.OT_TOIMITUS_MAAKOODI:{$order->extensionAttributes->shippingAssignments->item->shipping->address->countryId}\n";
    $edi_order .= "OSTOTIL.OT_TOIMITUS_PUH:{$order->extensionAttributes->shippingAssignments->item->shipping->address->telephone}\n";
    $edi_order .= "OSTOTIL.OT_TOIMITUS_EMAIL:{$order->customerEmail}\n";
    $edi_order .= "OSTOTIL.OT_TOIMITUS_NOUTOPISTE_TUNNUS:{$noutopistetunnus}\n";
    $edi_order .= "*RE OSTOTIL\n";

    $i = 1;

    //if only one product ordered, $order->items->item is an object not an array
    //in that case need to convert object to array for handling the order properly
    if(is_array($order->items->item) == false) {
      $order_items[] = $order->items->item;
    }
    else{
      $order_items = $order->items->item;
    }

    foreach ($order_items as $item) {
      $product_id = $item->productId;

      if ($item->productType != "configurable") {
        // Tuoteno
        $tuoteno = $item->sku;
        if (substr($tuoteno, 0, 4) == "SKU_") $tuoteno = substr($tuoteno, 4);

        // Nimitys
        $nimitys = $item->name;

        // M��r�
        $kpl = $item->qtyOrdered * 1;

        // Hinta pit�� hakea is�lt�
        if (isset($item->parentItemId)) {
          $parent_Item_Id = $item->parentItemId;
        }
        else {
          $parent_Item_Id = null;
        }
        $result = search_array_key_for_value_recursive($order->items->item, "itemId", $parent_Item_Id);

        // L�yty yks tai enemm�n, otetaan eka?
        if (count($result) != 0) {
          $_item = $result[0];
        }
        else {
          $_item = $item;
        }

        // Verollinen yksikk�hinta
        $verollinen_hinta = $_item->originalPrice;

        // Veroton yksikk�hinta
        $veroton_hinta = $_item->price;

        // Rivin alennusprosentti
        $alennusprosentti = $_item->discountPercent;

        // Rivin alennusm��r�
        $alennusmaara = $_item->baseDiscountAmount;

        // Jos alennusprosentti on 0, tarkistetaan viel� onko annettu eurom��r�ist� alennusta
        // Lahjakorttia ja eurom��r�ist� alennusta ei voi k�ytt�� samalla tilauksella, Magentossa estetty
        if ($alennusprosentti == 0 and $alennusmaara > 0 and $giftcard_sum == 0) {
          // Lasketaan alennusm��r� alennusprosentiksi
          $alennusprosentti = round(($alennusmaara * 100 / ($verollinen_hinta * $kpl)), 6);
        }

        // Verokanta
        $alvprosentti = $_item->taxPercent;

        // Verollinen rivihinta
        $rivihinta_verollinen = round(($verollinen_hinta * $kpl) * (1 - $alennusprosentti / 100), 6);

        // Veroton rivihinta
        $rivihinta_veroton = round(($veroton_hinta * $kpl) * (1 - $alennusprosentti / 100), 6);

        // Rivin tiedot
        $edi_order .= "*RS OSTOTILRIV {$i}\n";
        $edi_order .= "OSTOTILRIV.OTR_NRO:{$order->incrementId}\n";
        $edi_order .= "OSTOTILRIV.OTR_RIVINRO:{$i}\n";
        $edi_order .= "OSTOTILRIV.OTR_TOIMITTAJANRO:\n";
        $edi_order .= "OSTOTILRIV.OTR_TUOTEKOODI:{$tuoteno}\n";
        $edi_order .= "OSTOTILRIV.OTR_NIMI:{$nimitys}\n";
        $edi_order .= "OSTOTILRIV.OTR_TILATTUMAARA:{$kpl}\n";
        $edi_order .= "OSTOTILRIV.OTR_VEROKANTA:{$alvprosentti}\n";
        $edi_order .= "OSTOTILRIV.OTR_RIVISUMMA:{$rivihinta_veroton}\n";
        $edi_order .= "OSTOTILRIV.OTR_OSTOHINTA:{$veroton_hinta}\n";
        $edi_order .= "OSTOTILRIV.OTR_ALENNUS:{$alennusprosentti}\n";
        $edi_order .= "OSTOTILRIV.OTR_VIITE:\n";
        $edi_order .= "OSTOTILRIV.OTR_OSATOIMITUSKIELTO:\n";
        $edi_order .= "OSTOTILRIV.OTR_JALKITOIMITUSKIELTO:\n";
        $edi_order .= "OSTOTILRIV.OTR_YKSIKKO:\n";
        $edi_order .= "OSTOTILRIV.OTR_SALLITAANJT:0\n";
        $edi_order .= "*RE  OSTOTILRIV {$i}\n";

        $i++;
      }
    }

    // Rahtikulu, veroton
    $rahti_veroton = $order->extensionAttributes->shippingAssignments->item->shipping->total->shippingAmount;

    if ($rahti_veroton != 0) {
      // Rahtikulu, verollinen
      $rahti = $order->extensionAttributes->shippingAssignments->item->shipping->total->shippingAmount + $order->extensionAttributes->shippingAssignments->item->shipping->total->shippingTaxAmount;

      // Rahtin alviprossa
      $rahti_alvpros = round((($rahti / $rahti_veroton) - 1) * 100);

      if (isset($order->shippingDescription)) {
        $rahtikulu_nimitys .= " / {$order->shippingDescription}";
      }

      $edi_order .= "*RS OSTOTILRIV {$i}\n";
      $edi_order .= "OSTOTILRIV.OTR_NRO:{$order->incrementId}\n";
      $edi_order .= "OSTOTILRIV.OTR_RIVINRO:{$i}\n";
      $edi_order .= "OSTOTILRIV.OTR_TOIMITTAJANRO:\n";
      $edi_order .= "OSTOTILRIV.OTR_TUOTEKOODI:{$rahtikulu_tuoteno}\n";
      $edi_order .= "OSTOTILRIV.OTR_NIMI:{$rahtikulu_nimitys}\n";
      $edi_order .= "OSTOTILRIV.OTR_TILATTUMAARA:1\n";
      $edi_order .= "OSTOTILRIV.OTR_RIVISUMMA:{$rahti_veroton}\n";
      $edi_order .= "OSTOTILRIV.OTR_OSTOHINTA:{$rahti_veroton}\n";
      $edi_order .= "OSTOTILRIV.OTR_ALENNUS:0\n";
      $edi_order .= "OSTOTILRIV.OTR_VEROKANTA:{$rahti_alvpros}\n";
      $edi_order .= "OSTOTILRIV.OTR_VIITE:{$rahtikulu_nimitys}\n";
      $edi_order .= "OSTOTILRIV.OTR_OSATOIMITUSKIELTO:\n";
      $edi_order .= "OSTOTILRIV.OTR_JALKITOIMITUSKIELTO:\n";
      $edi_order .= "OSTOTILRIV.OTR_YKSIKKO:\n";
      $edi_order .= "*RE  OSTOTILRIV {$i}\n";
    }

    $edi_order .= "*ME\n";
    $edi_order .= "*IE\n";

    if (!PUPE_UNICODE) {
      $edi_order = iconv("UTF-8", "ISO-8859-1//TRANSLIT", $edi_order);
    }

    $name_prefix = "magento-order-{$order->incrementId}-".date("Ymd")."-";
    $file_dir    = $magento_api_ht_edi;
    $filename    = tempnam($file_dir, $name_prefix);
    unlink($filename);

    file_put_contents("{$filename}.txt", $edi_order);

    return "{$filename}.txt";
  }
}