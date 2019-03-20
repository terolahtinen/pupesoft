<?php
/**
 * SOAP-clientin wrapperi Magento-verkkokaupan p�ivitykseen
 *
 * K�ytet��n suoraan rajapinnat/tuote_export.php tiedostoa, jolla haetaan
 * tarvittavat tiedot pupesoftista.
 *
 * Lis�� tai p�ivitt�� kategoriat, tuotteet ja saldot.
 * Hakee tilauksia pupesoftiin.
 */


require_once "rajapinnat/edi.php";

class MagentoClient {

  // comma separated list of services for Magento 2 SOAP client
  // service order matters here, adding a new service might break functionality
  const DEFAULT_SERVICES = 'catalogProductAttributeManagementV1,catalogProductRepositoryV1,catalogInventoryStockRegistryV1,salesOrderRepositoryV1,salesOrderManagementV1,customerGroupRepositoryV1,catalogAttributeSetRepositoryV1,catalogProductAttributeOptionManagementV1,catalogProductAttributeMediaGalleryManagementV1';

  // Kutsujen m��r� multicall kutsulla
  const MULTICALL_BATCH_SIZE = 100;

  // Product visibility
  const NOT_VISIBLE_INDIVIDUALLY = 1;
  const CATALOG                  = 2;
  const SEARCH                   = 3;
  const CATALOG_SEARCH           = 4;

  // Product Status
  const ENABLED  = 1;
  const DISABLED = 2;

  // Soap client
  private $_proxy;

  // Soap clientin sessio
  private $_session;

  // Lokitetaanko debug infoa
  private $debug_logging = false;

  // Magenton oletus attributeSet
  private $_attributeSet;

  // Magenton attribuurit
  private $magento_attribute_list = null;

  // Tuotekategoriat
  private $_category_tree;

  // Verkkokaupan veroluokan tunnus
  private $_tax_class_id = 5;

  // Verkkokaupan "root" kategorian tunnus, t�m�n alle lis�t��n kaikki tuoteryhm�t
  private $_parent_id = 0;

  // Verkkokaupan "hinta"-kentt�, joko myymalahinta tai myyntihinta
  private $_hintakentta = "myymalahinta";

  // Verkkokaupassa k�ytett�v�t tuoteryhm�t, default tuoteryhm� tai tuotepuu
  private $_kategoriat = "tuoteryhma";

  // Onko "Category access control"-moduli on asennettu?
  private $_categoryaccesscontrol = false;

  // Tuotteella k�ytett�v� nimityskentt�
  private $_configurable_tuote_nimityskentta = "nimitys";
  private $magento_simple_tuote_nimityskentta = "nimitys";

  // first = otetaan is�tuotteen tiedot ensimm�iselt� lapselta
  // cheapest = otetaan is�tuotteen tiedot halvimmalta lapselta
  private $configurable_tuotetiedot = "first";

  // Miten configurable-tuotteen lapsituotteet n�ytet��n verkkokaupassa
  private $_configurable_lapsituote_nakyvyys = 'NOT_VISIBLE_INDIVIDUALLY';

  // Tuotteen erikoisparametrit, jotka tulevat jostain muualta kuin dynaamisista parametreist�
  private $_verkkokauppatuotteet_erikoisparametrit = array();

  // Asiakkaan erikoisparametrit, joilla ylikirjoitetaan arvoja asiakas- ja osoitetiedoista
  private $_asiakkaat_erikoisparametrit = array();

  // Magentossa k�sin hallitut kategoria id:t, joita ei poisteta tuotteelta tuotep�ivityksess�
  private $_sticky_kategoriat = array();

  // Estet��nk� tilauksen sis��nluku, jos se on jo kerran merkattu "processing_pupesoft"-tilaan
  private $_sisaanluvun_esto = "YES";

  // Asetetaan uusille tuotteille aina sama t�m� tuoteryhm�, p�ivityksess� ei kosketa tuoteryhmiin
  private $_universal_tuoteryhma = "";

  // Aktivoidaanko uusi asiakas Magentoon
  private $_asiakkaan_aktivointi = false;

  // Siirret��nk� asiakaskohtaiset tuotehinnat Magentoon
  private $_asiakaskohtaiset_tuotehinnat = false;

  // Lista Magenton default-tuoteparametreist�, jota ei ikin� p�ivitet�
  private $_magento_poistadefaultit = array();

  // Lista Magenton default-asiakasparametreist�, jota ei ikin� p�ivitet�
  private $_magento_poista_asiakasdefaultit = array();

  // Url-keyn luontia varten k�ytett�v�t parametrit
  private $_magento_url_key_attributes = array();

  // T�m�n yhteyden aikana sattuneiden virheiden m��r�
  private $_error_count = 0;

  // Poistetaanko tuotteita oletuksena
  private $_magento_poista_tuotteita = true;

  // K�sitell��nk� tuotekuvia magentossa
  private $magento_lisaa_tuotekuvat = true;

  // Miss� tilassa olevia tilauksia haetaan
  private $magento_fetch_order_status = 'Processing';

  // Perusteaanko tuotteet aina 'disabled' -tilassa
  private $magento_perusta_disabled = false;

  // Lis�t��nk� lapsituotteiden nimeen kaikki variaatioiden arvot
  private $magento_nimitykseen_parametrien_arvot = false;

  // Ovt tunnus, kenelle EDI-tilaukset tehd��n (yhtio.ovttunnus)
  private $ovt_tunnus = null;

  // Mik� on EDI-tilauksen tilaustyyppi
  private $pupesoft_tilaustyyppi = '';

  // Mik� on EDI-tilauksella rahtikulutuotteen nimitys
  private $rahtikulu_nimitys = 'L�hetyskulut';

  // Mik� on EDI-tilauksella rahtikulutuotteen tuotenumero (yhtio.rahti_tuotenumero)
  private $rahtikulu_tuoteno = null;

  // Mik� on EDI-tilauksella asiakasnumero, jolle tilaus tehd��n
  private $verkkokauppa_asiakasnro = null;

  // Minne hakemistoon EDI-tilaus tallennetaan
  private $edi_polku = '/tmp';

  // Korvaavia Maksuehtoja Magenton maksuehdoille
  private $magento_maksuehto_ohjaus = array();

  // Vaihoehtoisia OVT-tunnuksia EDI-tilaukselle
  private $magento_erikoiskasittely = array();

  // Merkataanko tuote tilaan 'varastossa' saldosta riippumatta
  private $tuote_aina_varastossa = null;

  // Default kielen lis�ksi muut tuetut kauppakohtaiset kieliversiot, esim array("en" => array('4','13'), "se" => array('9'));
  private $_TuetutKieliversiot = array();

  function __construct($base_url, $bearer/*, $client_options = array(), $debug = false*/) {
      global $magento_parent_root_category;
      $this->parent_id = $magento_parent_root_category;
      $this->base_url = $base_url;
      $this->bearer = $bearer;
  }

  // get client
  // $services: Magento 2 services, if empty uses default
  public function get_client($services = self::DEFAULT_SERVICES, $clientOptions = array()) {
       // add bearer
       $client_options['stream_context'] = 
         stream_context_create(array(
          'http' => array(
            'header' => 'Authorization: Bearer ' . $this->bearer
              )
            )
          );
       $client_options['trace'] = 1;
    $url = $this->base_url . $services;
    try {
      $client =  new SoapClient($url, $client_options);
    }
    catch (Exception $e) {
      $this->_error_count++;
      $this->log('magento_export', "Virhe! Magento-class init failed", $e);
    }

    return $client;
  }

  // Lis�� kaikki tai puuttuvat kategoriat Magento-verkkokauppaan.
  public function lisaa_kategoriat(array $dnsryhma) {
    $this->log('magento_kategoriat', "Lis�t��n kategoriat");

    $categoryaccesscontrol = $this->_categoryaccesscontrol;

    $selected_category = $this->_kategoriat;

    if ($selected_category != 'tuoteryhma') {
      $this->log('magento_kategoriat', "Ohitetaan kategorioiden luonti. Kategoriatyypiksi valittu tuotepuu.");
      return 0;
    }

    $parent_id = $this->_parent_id; // Magento kategorian tunnus, jonka alle kaikki tuoteryhm�t lis�t��n (pit�� katsoa magentosta)
    $count = 0;

    // Loopataan osastot ja tuoteryhmat
    foreach ($dnsryhma as $kategoria) {

      try {
        // Haetaan kategoriat joka kerta koska lis�tt�ess� puu muuttuu
        $category_tree = $this->getCategories();

        $kategoria['try_fi'] = utf8_encode($kategoria['try_fi']);
        // Kasotaan l�ytyyk� tuoteryhm�
        if (!$this->findCategory($kategoria['try_fi'], $category_tree['children'])) {

          // Lis�t��n kategoria, jos ei l�ytynyt
          $category_data = array(
            'name'                  => $kategoria['try_fi'],
            'is_active'             => 1,
            'position'              => 1,
            'default_sort_by'       => 'position',
            'available_sort_by'     => 'position',
            'include_in_menu'       => 1
          );

          if ($categoryaccesscontrol) {
            // HUOM: Vain jos "Category access control"-moduli on asennettu
            $category_data['accesscontrol_show_group'] = 0;
          }

          // Kutsutaan soap rajapintaa
          $client = $this->get_client($magento_api_category_repository);
          $category_id = $client->catalogCategoryRepositoryV1Save(
            array($parent_id, $category_data)
          );

          $count++;

          $this->log('magento_kategoriat', "Lis�ttiin kategoria {$kategoria['try_fi']}");
        }
      }
      catch (Exception $e) {
        $this->_error_count++;
        $this->log('magento_kategoriat', "Virhe! Kategoriaa {$kategoria['try_fi']} ei voitu lis�t�", $e);
      }
    }

    $this->_category_tree = $this->getCategories();
    $this->log('magento_kategoriat', "$count kategoriaa lis�tty");

    return $count;
  }

  // lis�� Simple -tuotteet Magentoon
  public function lisaa_simple_tuotteet(array $dnstuote, array $individual_tuotteet) {
    $this->log('magento_tuotteet', "Lis�t��n tuotteita (simple)");

    $hintakentta = $this->_hintakentta;
    $selected_category = $this->_kategoriat;
    $verkkokauppatuotteet_erikoisparametrit = $this->_verkkokauppatuotteet_erikoisparametrit;

    // Tuote countteri
    $count = 0;
    $total_count = count($dnstuote);

    //Categories not used in this implementation so not updated for magento 2
    // try {
    //   // Tarvitaan kategoriat
    //   $category_tree = $this->getCategories();

      // Haetaan storessa olevat tuotenumerot
      $skus_in_store = $this->getProductList(true);
    // }
    // catch (Exception $e) {
    //   $this->_error_count++;
    //   $this->log('magento_tuotteet', "Virhe! Tuotteiden lis�yksess� (simple)", $e);
    //   return;
    // }

    // Lis�t��n tuotteet eriss�
    foreach ($dnstuote as $tuote) {
      $tuote_clean = $tuote['tuoteno'];
      if (is_numeric($tuote['tuoteno'])) $tuote['tuoteno'] = "SKU_".$tuote['tuoteno'];

      $count++;
      $this->log('magento_tuotteet', "[{$count}/{$total_count}] K�sitell���n tuote '{$tuote['tuoteno']}' (simple)");
      $this->log('magento_tuotteet', "Asetetaan hinnaksi {$hintakentta} {$tuote[$hintakentta]}");

      $category_ids = array();

      $tuote['kuluprosentti'] = ($tuote['kuluprosentti'] == 0) ? '' : $tuote['kuluprosentti'];
      $tuoteryhmayliajo = $this->_universal_tuoteryhma;
      $tuoteryhmanimi   = $tuote['try_nimi'];
      $attribute_set_id = $this->get_attribute_set_id($tuote);

      // Yliajetaan tuoteryhm�n nimi jos muuttuja on asetettu
      if (!empty($tuoteryhmayliajo)) {
        $tuoteryhmanimi = $tuoteryhmayliajo;
      }

      // Etsit��n kategoria_id tuoteryhm�ll�
      if ($selected_category == 'tuoteryhma') {
        $category_ids[] = $this->findCategory(utf8_encode($tuoteryhmanimi), $category_tree['children']);
      }
      else {
        // Etsit��n kategoria_id:t tuotepuun tuotepolulla
        $tuotepuun_nodet = $tuote['tuotepuun_nodet'];

        // Lis�t��n my�s tuotepuun kategoriat
        if (isset($tuotepuun_nodet) and count($tuotepuun_nodet) > 0) {
          foreach ($tuotepuun_nodet as $tuotepolku) {
            $category_ids[] = $this->createCategoryTree($tuotepolku);
          }
        }
      }

      // Jos tuote ei oo osa configurable_grouppia, niin niitten kuuluu olla visibleja.
      if (isset($individual_tuotteet[$tuote_clean])) {
        $visibility = self::CATALOG_SEARCH;
      }
      else {
        $visibility = self::NOT_VISIBLE_INDIVIDUALLY;
      }

      $tuote_ryhmahinta_data = array();

      foreach ($tuote['asiakashinnat'] as $asiakashintarivi) {
        $asiakasryhma_nimi = $asiakashintarivi['asiakasryhma'];
        $asiakashinta = $asiakashintarivi['hinta'];
        $asiakasryhma_tunnus = $this->findCustomerGroup($asiakasryhma_nimi);

        if ($asiakasryhma_tunnus == 0) {
          continue;
        }

        $tuote_ryhmahinta_data[] = array(
          'customer_group_id' => $asiakasryhma_tunnus,
          'price'             => $asiakashinta,
          'qty'               => 1,
          'websites'          => explode(" ", $tuote['nakyvyys']),
        );
      }

      $multi_data = array();

      $_key = $this->magento_simple_tuote_nimityskentta;
      $tuotteen_nimitys = $tuote[$_key];

      // Simple tuotteiden parametrit kuten koko ja v�ri (oletuskieli on fi)
      if (!empty($tuote['tuotteen_parametrit']['fi'])) {
        foreach ($tuote['tuotteen_parametrit']['fi'] as $parametri) {
          $key = $parametri['option_name'];
          $option_id = $this->get_option_id($key, $parametri['arvo'], $attribute_set_id);

          if ($option_id === false) {
            continue;
          }

          $multi_data[$key] = $option_id;

          $this->log('magento_tuotteet', "Tuotteen parametri {$key}: {$parametri['arvo']} ({$multi_data[$key]})");

          // Lis�t��n lapsituotteen nimeen variaatioiden arvot
          if ($this->magento_nimitykseen_parametrien_arvot === true) {
            $tuotteen_nimitys .= " - {$parametri['arvo']}";
          }
        }
      }

      foreach ($verkkokauppatuotteet_erikoisparametrit as $erikoisparametri) {
        $key = $erikoisparametri['nimi'];

        // Kieliversiot
        // poimitaan talteen koska niit� k�ytet��n toisaalla
        if ($key == 'kieliversiot') {
          continue;
        }

        // ohitetaan kauppakohtaiset hinnat ja verokannat
        if ($key == 'kauppakohtaiset_hinnat') {
          continue;
        }

        if ($key == 'kauppakohtaiset_verokannat') {
          continue;
        }

        if (isset($tuote[$erikoisparametri['arvo']])) {
          $option_id = $this->get_option_id($key, $tuote[$erikoisparametri['arvo']], $attribute_set_id);

          if ($option_id === false) {
            $this->log('magento_tuotteet', "Erikoisparametri {$key}: {$tuote[$erikoisparametri['arvo']]} #option_id == 0, j�tet��n p�ivitt�m�tt�");
            continue;
          }

          $multi_data[$key] = $option_id;
          $this->log('magento_tuotteet', "Erikoisparametri {$key}: {$tuote[$erikoisparametri['arvo']]}");
        }
        else {
          $this->log('magento_tuotteet', "Erikoisparametri {$key}: {$tuote[$erikoisparametri['arvo']]} #!isset, j�tet��n p�ivitt�m�tt�");
        }
      }

      $tuote_data = array(
        'categories'            => $category_ids,
        'websites'              => explode(" ", $tuote['nakyvyys']),
        'name'                  => utf8_encode($tuotteen_nimitys),
        'description'           => utf8_encode($tuote['kuvaus']),
        'short_description'     => utf8_encode($tuote['lyhytkuvaus']),
        'weight'                => $tuote['paino'],
        'status'                => self::ENABLED,
        'visibility'            => $visibility,
        'price'                 => sprintf('%0.2f', $tuote[$hintakentta]),
        'tax_class_id'          => $this->_tax_class_id,
        'meta_title'            => '',
        'meta_keyword'          => '',
        'meta_description'      => '',
        'campaign_code'         => utf8_encode($tuote['campaign_code']),
        'onsale'                => utf8_encode($tuote['onsale']),
        'target'                => utf8_encode($tuote['target']),
        //'tier_price'            => $tuote_ryhmahinta_data,
        'additional_attributes' => array('multi_data' => $multi_data),
      );

      $tuote_data_up = array(
        //'categories'            => $category_ids,
        'websites'              => explode(" ", $tuote['nakyvyys']),
        'name'                  => utf8_encode($tuotteen_nimitys),
        //'description'           => utf8_encode($tuote['kuvaus']),
        'short_description'     => utf8_encode($tuote['lyhytkuvaus']),
        //'manufacturer'          => $tuote['tuotemerkki'],
        'weight'                => $tuote['paino'],
        'status'                => self::ENABLED,
        //'visibility'            => $visibility,
        'price'                 => sprintf('%0.2f', $tuote[$hintakentta]),
        'special_price'         => $tuote['hinnastohinta'],
        //'special_from_date'     => $tuote['hinnastoalku'],
        //'special_to_date'       => $tuote['hinnastoloppu'],
        'tax_class_id'          => $this->_tax_class_id,
        //'meta_title'            => '',
        //'meta_keyword'          => '',
        //'meta_description'      => '',
        'campaign_code'         => utf8_encode($tuote['campaign_code']),
        'onsale'                => utf8_encode($tuote['onsale']),
        'target'                => utf8_encode($tuote['target']),
        //'tier_price'            => $tuote_ryhmahinta_data,
        'additional_attributes' => array('multi_data' => $multi_data),
      );
      
      // Asetetaan tuotteen url_key mik�li parametrit m��ritelty
      if (count($this->_magento_url_key_attributes) > 0) {
        $tuote_data['url_key'] = $this->getUrlKeyForProduct($tuote);
      }

      $poista_defaultit = $this->_magento_poistadefaultit;

      // Voidaan yliajaa Magenton defaultparameja jos niit� ei haluta
      // tai jos ne halutaan korvata additional_attributesin mukana
      foreach ($poista_defaultit as $poistettava_key) {
        $this->log('magento_tuotteet', "Ei p�ivitet� kentt�� {$poistettava_key}");

        unset($tuote_data[$poistettava_key]);
      }

      //for magento 2 soap call, need to merge all custom attributes to the same array in 'attribute_code', 'value' format
      //these will be given as custom_attributes in the call

      $custom_attributes = [
        [
          'attributeCode' => 'category_ids',
          'value' => $tuote_data['categories']
        ],
        [
          'attributeCode' => 'description',
          'value' => $tuote_data['description']
        ],
        [
          'attributeCode' => 'short_description',
          'value' => $tuote_data['short_description']
        ],
        [
          'attributeCode' => 'tax_class_id',
          'value' => $tuote_data['tax_class_id']
        ],
        [
          'attributeCode' => 'campaign_code',
          'value' => $tuote_data['campaign_code']
        ],
        [
          'attributeCode' => 'onsale',
          'value' => $tuote_data['onsale']
        ],
        [
          'attributeCode' => 'target',
          'value' => $tuote_data['target']
        ]
      ];

      foreach($tuote_data['additional_attributes']['multi_data'] as $key => $value) {
        $custom_attributes [] = [
          'attributeCode' => $key,
          'value' => $value
        ];
      }

      // Jos tuotetta ei ole olemassa niin lis�t��n se
      if (!in_array($tuote['tuoteno'], $skus_in_store)) {
        $toiminto = 'create';

        try {
          // jos halutaan perustaa tuote disabled tilassa, muutetaan status
          if ($this->magento_perusta_disabled === true) {
            $tuote_data['status'] = self::DISABLED;
            $this->log('magento_tuotteet', "Asetetaan tuote Disabled -tilaan");
          }

          $create_product_values = [
            'product' => [
              'sku' => $tuote['tuoteno'],
              'name' => $tuote_data['name'],
              'attributeSetId' => $attribute_set_id,
              'price' => $tuote_data['price'],
              'status' => $tuote_data['status'],
              'typeId' => 'simple',
              'weight' => $tuote_data['weight'],
              'visibility' => $tuote_data['visibility'],
              'extensionAttributes' => [
                'websiteIds' => $tuote_data['websites']
              ],
              'custom_attributes' => $custom_attributes
            ]
          ];

          $product_id = $this->get_client()->catalogProductRepositoryV1Save($create_product_values);

          $this->log('magento_tuotteet', "Tuote lis�tty");
          $this->debug('magento_tuotteet', $tuote_data);

          $is_in_stock = $this->tuote_aina_varastossa === true ? 1 : 0;

          // Pit�� k�yd� tekem�ss� viel� stock.update kutsu, ett� saadaan Manage Stock: YES

          $sku_for_status = [
            'productSku' => $tuote['tuoteno']
          ];

          $current_stock_status = $this->get_client()->catalogInventoryStockRegistryV1GetStockStatusBySku($sku_for_status);
          $updated_stock_status = $current_stock_status->result->stockItem;
          $updated_stock_status->qty = 0;
          $updated_stock_status->manageStock = 1;
          $updated_stock_status->isInStock = $is_in_stock;

          $updated_status = [
            'productSku' => $tuote['tuoteno'],
            'stockItem' => $updated_stock_status
          ];

          $result = $this->get_client()->catalogInventoryStockRegistryV1UpdateStockItemBySku($updated_status);

        }
        catch (Exception $e) {
          $this->_error_count++;
          $this->log('magento_tuotteet', "Virhe! Tuotteen lis�ys ep�onnistui", $e);
          $this->debug('magento_tuotteet', $tuote_data);

          continue;
        }
      }
      // Tuote on jo olemassa, p�ivitet��n
      else {
        $toiminto = 'update';

        try {

          $sticky_kategoriat = $this->_sticky_kategoriat;
          $tuoteryhmayliajo = $this->_universal_tuoteryhma;

          // Haetaan tuotteen Magenton ID ja nykyiset kategoriat
          $sku_for_update = [
            'sku' => $tuote['tuoteno']
          ];

          $result = $this->get_client()->catalogProductRepositoryV1Get($sku_for_update);
          $product_id = $result->result->id;
          foreach($result->result->customAttributes->item as $attributes) {
            if (strcasecmp($attributes->attributeCode, 'category_ids') == 0) {
              $current_categories = $attributes->value; //tämän hetkiset kategoriat
              break;
            }
          }

          // Jos tuotteelta l�ytyy n�it� kategoriatunnuksia ennen updatea ne lis�t��n takaisin
          if (count($sticky_kategoriat) > 0 and count($current_categories) > 0) {
            foreach ($sticky_kategoriat as $stick) {
              if (in_array($stick, $current_categories)) {
                $tuote_data['categories'][] = $stick;
              }
            }
          }

          // Ei muuteta tuoteryhmi� jos yliajo on p��ll�
          if (!empty($tuoteryhmayliajo)) {
            $tuote_data['categories'] = $current_categories;
          }

          //values as according to $tuote_data_up array
          $custom_attributes_update = [
            // [
            //   'attributeCode' => 'category_ids',
            //   'value' => $tuote_data['categories']
            // ],
            // [
            //   'attributeCode' => 'description',
            //   'value' => $tuote_data['description']
            // ],
            [
              'attributeCode' => 'short_description',
              'value' => $tuote_data['short_description']
            ],
            [
              'attributeCode' => 'tax_class_id',
              'value' => $tuote_data['tax_class_id']
            ],
            [
              'attributeCode' => 'campaign_code',
              'value' => $tuote_data['campaign_code']
            ],
            [
              'attributeCode' => 'onsale',
              'value' => $tuote_data['onsale']
            ],
            [
              'attributeCode' => 'target',
              'value' => $tuote_data['target']
            ],
            [
              'attributeCode' => 'special_price',
              'value' => $tuote_data_up['special_price']
            ]
          ];

          foreach($tuote_data_up['additional_attributes']['multi_data'] as $key => $value) {
            $custom_attributes_update [] = [
              'attributeCode' => $key,
              'value' => $value
            ];
          }

          $update_product_values = [
            'product' => [
              'sku' => $tuote['tuoteno'],
              'name' => $tuote_data_up['name'],
              'price' => $tuote_data_up['price'],
              'status' => $tuote_data_up['status'],
              'weight' => $tuote_data_up['weight'],
              // 'visibility' => $tuote_data['visibility'],
              'extensionAttributes' => [
                'websiteIds' => $tuote_data_up['websites']
              ],
              'custom_attributes' => $custom_attributes_update
            ]
          ];



          $this->get_client()->catalogProductRepositoryV1Save($update_product_values);


          $this->log('magento_tuotteet', "Tuotetiedot p�ivitetty");
          $this->debug('magento_tuotteet', $tuote_data_up);
        }
        catch (Exception $e) {
          $this->_error_count++;
          $this->log('magento_tuotteet', "Virhe! Tuotteen p�ivitys ep�onnistui", $e);
          $this->debug('magento_tuotteet', $tuote_data_up);

          continue;
        }
      }

      // P�ivitet��n tuotteen kieliversiot kauppan�kym�kohtaisesti
      // jos n�m� on asetettu konffissa
      if (count($this->_TuetutKieliversiot) > 0) {
        try {
          // Kieliversiot-magentoerikoisparametrin tulee sis�lt�� array jossa m��ritell��n mik� kieliversio
          // siirret��n mihinkin kauppatunnukseen

          // Esim. array("en" => array('4','13'), "se" => array('9'));
          $kieliversio_data = $this->hae_kieliversiot($tuote_clean);

          // katsotaan onko $verkkokauppatuotteet_erikoisparametrit taulukossa m��ritelty mainosteksti�.
          $_mainosteksti = array();
          foreach ($verkkokauppatuotteet_erikoisparametrit as $spessukentat) {

            // spessukent�t on m��ritelty taulukossa niin, ett� array(nimi => magentonimi, arvo => pupenimi)
            // katsotaan onko mainostekstille m��ritelty kentt�� Magentossa
            if (isset($spessukentat['nimi']) and $spessukentat['arvo'] == 'mainosteksti') {
              $_mainosteksti[] = $spessukentat['nimi'];
            }
          }

          foreach ($this->_TuetutKieliversiot as $kieli => $kauppatunnukset) {
            if (empty($kieliversio_data[$kieli])) continue;

            $kaannokset = $kieliversio_data[$kieli];
            $tuotteen_kauppakohtainen_data2 = array();
            $tuotteen_kauppakohtainen_data3 = array();

            if (!empty($_mainosteksti)) {
              foreach ($_mainosteksti as $_magento_fieldname) {
                $tuotteen_kauppakohtainen_data2[$_magento_fieldname] = $kaannokset['mainosteksti'];
              }

              $this->log('magento_tuotteet', "Tuotteen mainosteksti kielik��nn�s {$kieli}: {$tuote['tuoteno']} - {$_magento_fieldname}: {$kaannokset['mainosteksti']}");
            }

            // Simple tuotteiden parametrit kuten koko ja v�ri
            if (!empty($tuote['tuotteen_parametrit'][$kieli])) {
              foreach ($tuote['tuotteen_parametrit'][$kieli] as $parametri) {
                $key = $parametri['option_name'];
                $option_id = $this->get_option_id($key, $parametri['arvo'], $attribute_set_id);

                if ($option_id === false) {
                  continue;
                }

                $multi_data[$key] = $option_id;

                $this->log('magento_tuotteet', "Tuotteen parametri kielik��nn�s {$kieli} {$tuote['tuoteno']} - {$key}: {$parametri['arvo']} ({$multi_data[$key]})");

                // Lis�t��n lapsituotteen nimeen variaatioiden arvot
                if ($this->magento_nimitykseen_parametrien_arvot === true) {
                  $kaannokset['nimitys'] .= " - {$parametri['arvo']}";
                }

                $tuotteen_kauppakohtainen_data3['additional_attributes'] = array('multi_data' => $multi_data);
              }
            }

            // P�ivitet��n jokaiseen kauppatunnukseen haluttu k��nn�s
            foreach ($kauppatunnukset as $kauppatunnus) {

              $tuotteen_kauppakohtainen_data = array(
                'description' => $kaannokset['kuvaus'],
                'name'        => $kaannokset['nimitys'],
                'unit'        => $kaannokset['yksikko'],
              );

              $tuotteen_kauppakohtainen_data = array_merge($tuotteen_kauppakohtainen_data, $tuotteen_kauppakohtainen_data2, $tuotteen_kauppakohtainen_data3);

              $this->_proxy->call($this->_session, 'catalog_product.update',
                array(
                  $tuote['tuoteno'],
                  $tuotteen_kauppakohtainen_data,
                  $kauppatunnus
                )
              );
            }
          }

          $this->log('magento_tuotteet', "Kieliversiot p�ivitetty");
          $this->debug('magento_tuotteet', $kieliversio_data);
        }
        catch (Exception $e) {
          $this->log('magento_tuotteet', "Virhe! Kieliversioiden p�ivitys ep�onnistui", $e);
          $this->debug('magento_tuotteet', $kieliversio_data);
          $this->_error_count++;
        }
      }

      // P�ivitet��n tuotteen kauppan�kym�kohtaiset hinnat
      $tuotteen_kauppakohtaiset_hinnat = $this->kauppakohtaiset_hinnat($tuote);

      foreach ($tuotteen_kauppakohtaiset_hinnat as $kauppatunnus => $tuotteen_kauppakohtainen_data) {
        try {
          $this->_proxy->call($this->_session, 'catalog_product.update',
            array(
              $tuote['tuoteno'],
              $tuotteen_kauppakohtainen_data,
              $kauppatunnus
            )
          );
        }
        catch (Exception $e) {
          $this->_error_count++;
          $this->log('magento_tuotteet', "Virhe! Kauppakohtaisen hinnan p�ivitys ep�onnistui", $e);
          $this->debug('magento_tuotteet', $tuotteen_kauppakohtainen_data);
        }
      }

      // Lis�t��n kuvat Magentoon
      $this->lisaa_ja_poista_tuotekuvat($product_id, $tuote['tunnus'], $toiminto, $tuote['tuoteno']);

      // Lis�t��n tuotteen asiakaskohtaiset tuotehinnat
      if ($this->_asiakaskohtaiset_tuotehinnat) {
        $this->lisaaAsiakaskohtaisetTuotehinnat($tuote_clean, $tuote['tuoteno']);
      }
    }

    $this->log('magento_tuotteet', "$count tuotetta p�ivitetty (simple)");

    // Palautetaan p�vitettyjen tuotteiden m��r�
    return $count;
  }

  // Lis�� Configurable -tuotteet Magentoon
  public function lisaa_configurable_tuotteet(array $dnslajitelma) {
    $this->log('magento_tuotteet', "Lis�t��n tuotteet (configurable)");

    $count = 0;
    $total_count = count($dnslajitelma);

    try {
      // Tarvitaan kategoriat
      $category_tree = $this->getCategories();

      // Haetaan storessa olevat tuotenumerot
      $skus_in_store = $this->getProductList(true);
    }
    catch (Exception $e) {
      $this->_error_count++;
      $this->log('magento_tuotteet', "Virhe! Tuotteiden lis�yksess� (config)", $e);

      return;
    }

    $hintakentta = $this->_hintakentta;

    // Erikoisparametrit
    $verkkokauppatuotteet_erikoisparametrit = $this->_verkkokauppatuotteet_erikoisparametrit;

    // Mit� kentt�� k�ytet��n configurable_tuotteen nimen�
    $configurable_tuote_nimityskentta = $this->_configurable_tuote_nimityskentta;

    $selected_category = $this->_kategoriat;

    // Lis�t��n tuotteet
    foreach ($dnslajitelma as $nimitys => $tuotteet) {
      if (is_numeric($nimitys)) $nimitys = "SKU_{$nimitys}";

      # valitaan lapsituote, jonka tietoja k�ytet��n is�tuotteella. oletuksena ensimm�inen lapsituote.
      $lapsituotteen_tiedot = $tuotteet[0];

      # halutaan is�tuotteelle halvimman lapsen tiedot
      if ($this->configurable_tuotetiedot == 'cheapest') {
        $lapsituotteen_tiedot = search_array_min_with_key($tuotteet, $hintakentta);
      }

      $count++;
      $this->log('magento_tuotteet', "[{$count}/{$total_count}] K�sitell��n tuote {$nimitys} (configurable)");
      $this->log('magento_tuotteet', "Is�tuotteen tiedot tuotteelta {$lapsituotteen_tiedot['tuoteno']}");
      $this->log('magento_tuotteet', "Asetetaan hinnaksi {$hintakentta} {$lapsituotteen_tiedot[$hintakentta]}");

      $category_ids = array();

      // Erikoishinta
      $lapsituotteen_tiedot['kuluprosentti'] = ($lapsituotteen_tiedot['kuluprosentti'] == 0) ? '' : $lapsituotteen_tiedot['kuluprosentti'];
      $tuoteryhmayliajo = $this->_universal_tuoteryhma;
      $tuoteryhmanimi = $lapsituotteen_tiedot['try_nimi'];

      // attribute setin id
      $attribute_set_id = $this->get_attribute_set_id($lapsituotteen_tiedot);

      // Yliajetaan tuoteryhm�n nimi jos muuttuja on asetettu
      if (!empty($tuoteryhmayliajo)) {
        $tuoteryhmanimi = $tuoteryhmayliajo;

        $this->log('magento_tuotteet', "Asetetaan tuote vakiokategoriaan '{$tuoteryhmayliajo}'");
      }

      // Etsit��n kategoria_id tuoteryhm�ll�
      if ($selected_category == 'tuoteryhma') {
        $category_ids[] = $this->findCategory($tuoteryhmanimi, $category_tree['children']);
      }
      else {
        // Etsit��n kategoria_id:t tuotepuun tuotepolulla
        $tuotepuun_nodet = $lapsituotteen_tiedot['tuotepuun_nodet'];

        // Lis�t��n my�s tuotepuun kategoriat
        if (isset($tuotepuun_nodet) and count($tuotepuun_nodet) > 0) {
          foreach ($tuotepuun_nodet as $tuotepolku) {
            $category_ids[] = $this->createCategoryTree($tuotepolku);
          }
        }
      }

      // Tehd��n 'associated_skus' -kentt�
      // Vaatii, ett� Magentoon asennetaan 'magento-improve-api' -moduli: https://github.com/jreinke/magento-improve-api
      $lapsituotteet_array = array();

      foreach ($tuotteet as $tuote) {
        if (is_numeric($tuote['tuoteno'])) $tuote['tuoteno'] = "SKU_".$tuote['tuoteno'];

        $lapsituotteet_array[] = $tuote['tuoteno'];
      }

      // Configurable-tuotteelle my�s ensimm�isen lapsen parametrit
      $configurable_multi_data = array();

      if (!empty($lapsituotteen_tiedot['parametrit']['fi'])) {
        foreach ($lapsituotteen_tiedot['parametrit']['fi'] as $parametri) {
          $key = $parametri['option_name'];
          $option_id = $this->get_option_id($key, $parametri['arvo'], $attribute_set_id);

          if ($option_id === false) {
            continue;
          }

          $configurable_multi_data[$key] = $option_id;

          $this->log('magento_tuotteet', "Tuotteen parametri {$key}: {$parametri['arvo']} ({$configurable_multi_data[$key]})");
        }
      }

      // Configurable-tuotteelle my�s ensimm�isen lapsen erikoisparametrit
      foreach ($verkkokauppatuotteet_erikoisparametrit as $erikoisparametri) {
        $key = $erikoisparametri['nimi'];

        if ($key == 'kieliversiot') {
          continue;
        }

        // ohitetaan kauppakohtaiset hinnat ja verokannat
        if ($key == 'kauppakohtaiset_hinnat') {
          continue;
        }

        if ($key == 'kauppakohtaiset_verokannat') {
          continue;
        }

        if (isset($lapsituotteen_tiedot[$erikoisparametri['arvo']])) {
          $option_id = $this->get_option_id($key, $lapsituotteen_tiedot[$erikoisparametri['arvo']], $attribute_set_id);

          if ($option_id === false) {
            continue;
          }

          $configurable_multi_data[$key] = $option_id;
          $this->log('magento_tuotteet', "Erikoisparametri {$key}: {$lapsituotteen_tiedot[$erikoisparametri['arvo']]}");
        }
      }

      // Configurable tuotteen tiedot
      $configurable = array(
        'categories'            => $category_ids,
        'websites'              => explode(" ", $lapsituotteen_tiedot['nakyvyys']),
        'name'                  => utf8_encode($lapsituotteen_tiedot[$configurable_tuote_nimityskentta]),
        'description'           => utf8_encode($lapsituotteen_tiedot['kuvaus']),
        'short_description'     => utf8_encode($lapsituotteen_tiedot['lyhytkuvaus']),
        'campaign_code'         => utf8_encode($lapsituotteen_tiedot['campaign_code']),
        'onsale'                => utf8_encode($lapsituotteen_tiedot['onsale']),
        'target'                => utf8_encode($lapsituotteen_tiedot['target']),
        'featured_priority'     => utf8_encode($lapsituotteen_tiedot['jarjestys']),
        'weight'                => $lapsituotteen_tiedot['paino'],
        'status'                => self::ENABLED,
        'visibility'            => self::CATALOG_SEARCH, // Configurablet nakyy kaikkialla
        'price'                 => $lapsituotteen_tiedot[$hintakentta],
        'tax_class_id'          => $this->_tax_class_id,
        'meta_title'            => '',
        'meta_keyword'          => '',
        'meta_description'      => '',
        'additional_attributes' => array('multi_data' => $configurable_multi_data),
        'associated_skus'       => $lapsituotteet_array,
      );

      // Asetetaan configurable-tuotteen url_key mik�li parametrit m��ritelty
      if (count($this->_magento_url_key_attributes) > 0) {
        $configurable['url_key'] = utf8_encode($this->sanitize_link_rewrite($nimitys));
      }

      $poista_defaultit = $this->_magento_poistadefaultit;

      // Voidaan yliajaa Magenton defaultparameja jos niit� ei haluta
      // tai jos ne halutaan korvata additional_attributesin mukana
      foreach ($poista_defaultit as $poistettava_key) {
        $this->log('magento_tuotteet', "Ei p�ivitet� kentt�� {$poistettava_key}");

        unset($configurable[$poistettava_key]);
      }

      try {

        /**
         * Loopataan tuotteen (configurable) lapsituotteet (simple) l�pi
         * ja p�ivitet��n niiden attribuutit kuten koko ja v�ri.
         */
        foreach ($tuotteet as $tuote) {
          if (is_numeric($tuote['tuoteno'])) $tuote['tuoteno'] = "SKU_".$tuote['tuoteno'];

          $multi_data = array();

          // Simple tuotteiden parametrit kuten koko ja v�ri
          if (!empty($tuote['parametrit']['fi'])) {
            foreach ($tuote['parametrit']['fi'] as $parametri) {
              $key = $parametri['option_name'];
              $option_id = $this->get_option_id($key, $parametri['arvo'], $attribute_set_id);

              if ($option_id === false) {
                continue;
              }

              $multi_data[$key] = $option_id;
              $this->log('magento_tuotteet', "Tuotteen parametri {$key}: {$parametri['arvo']} ({$multi_data[$key]})");
            }
          }

          $simple_tuote_data = array(
            'price'                 => $tuote[$hintakentta],
            'short_description'     => utf8_encode($tuote['lyhytkuvaus']),
            'featured_priority'     => utf8_encode($tuote['jarjestys']),
            'visibility'            => constant("MagentoClient::{$this->_configurable_lapsituote_nakyvyys}"),
            'additional_attributes' => array('multi_data' => $multi_data),
          );

          // P�ivitet��n Simple tuote
          $result = $this->_proxy->call(
            $this->_session,
            'catalog_product.update',
            array($tuote['tuoteno'], $simple_tuote_data)
          );

          $this->log('magento_tuotteet', "P�ivitet��n lapsituote '{$tuote['tuoteno']}'");
          $this->debug('magento_tuotteet', $simple_tuote_data);
        }

        // Jos configurable tuotetta ei l�ydy, niin lis�t��n uusi tuote.
        if (!in_array($nimitys, $skus_in_store)) {
          $toiminto = 'create';

          // jos halutaan perustaa tuote disabled tilassa, muutetaan status
          if ($this->magento_perusta_disabled === true) {
            $configurable['status'] = self::DISABLED;
          }

          $product_id = $this->_proxy->call(
            $this->_session,
            'catalog_product.create',
            array(
              'configurable',
              $attribute_set_id,
              $nimitys, // sku
              $configurable
            )
          );

          $this->log('magento_tuotteet', "Tuote lis�tty");
          $this->debug('magento_tuotteet', $configurable);
        }
        // P�ivitet��n olemassa olevaa configurablea
        else {
          $toiminto = 'update';
          $sticky_kategoriat = $this->_sticky_kategoriat;
          $tuoteryhmayliajo = $this->_universal_tuoteryhma;

          // Haetaan tuotteen Magenton ID ja nykyiset kategoriat
          $result = $this->_proxy->call($this->_session, 'catalog_product.info', $nimitys);
          $product_id = $result['product_id'];
          $current_categories = $result['categories'];

          // Jos tuotteelta l�ytyy n�it� kategoriatunnuksia ennen updatea ne lis�t��n takaisin
          if (count($sticky_kategoriat) > 0 and count($current_categories) > 0) {
            foreach ($sticky_kategoriat as $stick) {
              if (in_array($stick, $current_categories)) {
                $configurable['categories'][] = $stick;
              }
            }
          }

          // Ei muuteta tuoteryhmi� jos yliajo on p��ll�
          if (!empty($tuoteryhmayliajo)) {
            $configurable['categories'] = $current_categories;
          }

          $this->_proxy->call($this->_session, 'catalog_product.update',
            array(
              $nimitys,
              $configurable
            )
          );

          $this->log('magento_tuotteet', "Tuotetiedot p�ivitetty");
          $this->debug('magento_tuotteet', $configurable);
        }

        // Pit�� k�yd� tekem�ss� viel� stock.update kutsu, ett� saadaan Manage Stock: YES
        $stock_data = array(
          'is_in_stock'  => 1,
          'manage_stock' => 1,
        );

        $result = $this->_proxy->call(
          $this->_session,
          'product_stock.update',
          array(
            $nimitys, // sku
            $stock_data
          )
        );

        // P�ivitet��n tuotteen kauppan�kym�kohtaiset hinnat
        $tuotteen_kauppakohtaiset_hinnat = $this->kauppakohtaiset_hinnat($lapsituotteen_tiedot);

        foreach ($tuotteen_kauppakohtaiset_hinnat as $kauppatunnus => $tuotteen_kauppakohtainen_data) {
          try {
            $this->_proxy->call($this->_session, 'catalog_product.update',
              array(
                $nimitys,
                $tuotteen_kauppakohtainen_data,
                $kauppatunnus
              )
            );
          }
          catch (Exception $e) {
            $this->_error_count++;
            $this->log('magento_tuotteet', "Virhe! Kauppakohtaisen hinnan p�ivitys ep�onnistui", $e);
            $this->debug('magento_tuotteet', $tuotteen_kauppakohtainen_data);
          }
        }

        // Lis�t��n kuvat Magentoon
        $this->lisaa_ja_poista_tuotekuvat($product_id, $lapsituotteen_tiedot['tunnus'], $toiminto);
      }
      catch (Exception $e) {
        $this->_error_count++;
        $this->log('magento_tuotteet', "Virhe! Tuotteen {$toiminto} ep�onnistui", $e);
        $this->debug('magento_tuotteet', $configurable);
      }
    }

    $this->log('magento_tuotteet', "$count tuotetta p�ivitetty (configurable)");

    // Palautetaan lis�ttyjen configurable tuotteiden m��r�
    return $count;
  }

  // Hakee kaikki tilaukset Magentosta ja tallentaa ne edi_tilauksiksi
  public function tallenna_tilaukset() {
    // status, mit� tilauksia haetaan
    $status = $this->magento_fetch_order_status;

    // EDI-tilauksen luontiin tarvittavat parametrit
    $options = array(
      'edi_polku'          => $this->edi_polku,
      'ovt_tunnus'         => $this->ovt_tunnus,
      'rahtikulu_nimitys'  => $this->rahtikulu_nimitys,
      'rahtikulu_tuoteno'  => $this->rahtikulu_tuoteno,
      'tilaustyyppi'       => $this->pupesoft_tilaustyyppi,
      'asiakasnro'         => $this->verkkokauppa_asiakasnro,
      'maksuehto_ohjaus'   => $this->magento_maksuehto_ohjaus,
      'erikoiskasittely'   => $this->magento_erikoiskasittely,
    );

    // Haetaan tilaukset magentosta
    try {
      $tilaukset = $this->hae_tilaukset($status);
    }
    catch (Exception $e) {
      $this->log('magento_tilaukset', "Tilausten haku ep�onnistui", $e);
      exit;
    }

    // Tallennetaan EDI-tilauksina
    foreach ($tilaukset as $tilaus) {
      $filename = Edi::create($tilaus, $options);

      $this->log('magento_tilaukset', "Tallennettiin tilaus '{$filename}'");
      $this->debug('magento_tilaukset', $tilaus);
    }
  }

  // P�ivitet��n sadot
  public function paivita_saldot(array $dnstock) {
    $this->log('magento_saldot', "P�ivitet��n saldot");

    $count = 0;
    $total_count = count($dnstock);

    // Loopataan p�ivitett�v�t tuotteet l�pi (aina simplej�)
    foreach ($dnstock as $tuote) {
      if (is_numeric($tuote['tuoteno'])) $tuote['tuoteno'] = "SKU_".$tuote['tuoteno'];

      // $tuote muuttuja sis�lt�� tuotenumeron ja myyt�viss� m��r�n
      $product_sku = $tuote['tuoteno'];
      $qty         = $tuote['myytavissa'];

      $count++;
      $this->log('magento_saldot', "[{$count}/{$total_count}] P�ivitet��n tuotteen {$product_sku} saldo {$qty}");

      // Out of stock jos m��r� on tuotteella ei ole myytavissa saldoa ja jos tuotteet aina varastossa parametri ei ole p��ll�
      $is_in_stock = ($qty > 0 or $this->tuote_aina_varastossa === true) ? 1 : 0;

      // P�ivitet��n saldo
      try {
        
        //catalogInventoryStockRegistryV1GetStockItemBySku method needs product sku in array
        $product_sku_array = [
          'productSku' => $product_sku
        ];

        $result_stock = $this->get_client()->catalogInventoryStockRegistryV1GetStockItemBySku($product_sku_array);
        $result_stock->result->qty = $qty;
        $updated_values = (array) $result_stock->result;
        
        $product_to_update = [
          'productSku' => $product_sku,
          'stockItem' => $updated_values
        ];

        $result = $this->get_client()->catalogInventoryStockRegistryV1UpdateStockItemBySku($product_to_update);

        // $stock_data = array(
        //   'qty'          => $qty,
        //   'is_in_stock'  => $is_in_stock,
        //   'manage_stock' => 1
        // );

        // $result = $this->_proxy->call(
        //   $this->_session,
        //   'product_stock.update',
        //   array(
        //     $product_sku,
        //     $stock_data
        //   )
        // );
      }
      catch (Exception $e) {
        $this->_error_count++;
        $this->log('magento_saldot', "Virhe! Saldop�ivitys ep�onnistui!", $e);
      }

      // Jos meill� on "erikoissaldoja" tuotteelle, pit�� n�m� tiedot p�ivitt�� tuotetietoihin
      $this->paivita_erikoissaldot($tuote['tuoteno'], $tuote['vaihtoehtoiset_saldot']);
    }

    $this->log('magento_saldot', "$count saldoa p�ivitetty");

    return $count;
  }

  // P�ivitet��n erikoissaldot
  public function paivita_erikoissaldot($tuoteno, Array $erikoissaldot) {
    // ei tehd� mit��n, jos ei ole erikoissaldoja
    if (count($erikoissaldot) == 0) {
      return false;
    }

    $log_keys   = implode(', ', array_keys($erikoissaldot));
    $log_values = implode(', ', array_values($erikoissaldot));
    $log_info   = "Kent�t {$log_keys}. Arvot {$log_values}.";

    try {
      // P�ivitet��n tuote
      $result = $this->_proxy->call(
        $this->_session,
        'catalog_product.update',
        array($tuoteno, $erikoissaldot)
      );

      $this->log('magento_saldot', "Erikoissaldot lis�tty. {$log_info}");
    }
    catch (Exception $e) {
      $this->_error_count++;
      $this->log('magento_saldot', "Virhe! Erikoissaldop�ivitys ep�onnistui! {$log_info}", $e);

      return false;
    }

    return true;
  }

  // Poistaa magentosta tuotteita
  // HUOM, t�h�n passataan aina **KAIKKI** verkkokauppatuotteet.
  public function poista_poistetut(array $kaikki_tuotteet, $exclude_giftcards = false) {
    if ($this->_magento_poista_tuotteita !== true) {
      $this->log('magento_tuotteet', "Tuoteiden poisto kytketty pois p��lt�.");

      return 0;
    }

    $skus = $this->getProductList(true, $exclude_giftcards);

    // Loopataan $kaikki_tuotteet-l�pi ja tehd��n numericmuutos
    foreach ($kaikki_tuotteet as &$tuote) {
      if (is_numeric($tuote)) $tuote = "SKU_".$tuote;
    }

    // Poistetaan tuottee jotka l�ytyv�t arraysta $kaikki_tuotteet arrayst� $skus
    $poistettavat_tuotteet = array_diff($skus, $kaikki_tuotteet);

    $poistettu = 0;
    $count = 0;
    $total_count = count($poistettavat_tuotteet);


    // N�m� kaikki tuotteet pit�� poistaa Magentosta
    foreach ($poistettavat_tuotteet as $tuote) {
      $count++;
      $this->log('magento_tuotteet', "[{$count}/{$total_count}] Poistetaan tuote '$tuote'");

      $tuote_array_fordelete = [
        'sku' => $tuote
    ];

      try {
        // T�ss� kutsu, jos tuote oikeasti halutaan poistaa
        $this->get_client()->catalogProductRepositoryV1DeleteById($tuote_array_fordelete);
        $poistettu++;
      }
      catch (Exception $e) {
        $this->_error_count++;
        $this->log('magento_tuotteet', "Virhe! Poisto ep�onnistui!", $e);
      }
    }

    $this->log('magento_tuotteet', "$poistettu tuotetta poistettu");

    return $poistettu;
  }

  // Lis�� asiakkaita Magento-verkkokauppaan.
  public function lisaa_asiakkaat(array $dnsasiakas) {
    $this->log('magento_asiakkaat', "Lis�t��n asiakkaita");
    // Asiakas countteri
    $count = 0;
    $total_count = count($dnsasiakas);

    // Asiakkaiden erikoisparametrit
    $asiakkaat_erikoisparametrit = $this->_asiakkaat_erikoisparametrit;

    // Lis�t��n asiakkaat ja osoitteet eriss�
    foreach ($dnsasiakas as $asiakas) {
      $count++;
      $this->log('magento_asiakkaat', "[{$count}/{$total_count}] Asiakas '{$asiakas['nimi']}'");

      $asiakasryhma_id = $this->findCustomerGroup($asiakas['asiakasryhma']);

      $asiakas_data = array(
        'email'       => utf8_encode($asiakas['yhenk_email']),
        'firstname'   => utf8_encode($asiakas['nimi']),
        'lastname'    => utf8_encode($asiakas['nimi']),
        'website_id'  => utf8_encode($asiakas['magento_website_id']),
        'taxvat'      => $asiakas['ytunnus'],
        'external_id' => $asiakas['asiakasnro'],
        'group_id'    => $asiakasryhma_id,
      );

      $laskutus_osoite_data = array(
        'firstname'  => utf8_encode($asiakas['laskutus_nimi']),
        'lastname'   => utf8_encode($asiakas['laskutus_nimi']),
        'street'     => array(utf8_encode($asiakas['laskutus_osoite'])),
        'postcode'   => utf8_encode($asiakas['laskutus_postino']),
        'city'       => utf8_encode($asiakas['laskutus_postitp']),
        'country_id' => utf8_encode($asiakas['maa']),
        'telephone'  => utf8_encode($asiakas['yhenk_puh']),
        'company'    => utf8_encode($asiakas['nimi']),
        'is_default_billing'    => true,
      );

      $toimitus_osoite_data = array(
        'firstname'  => utf8_encode($asiakas['toimitus_nimi']),
        'lastname'   => utf8_encode($asiakas['toimitus_nimi']),
        'street'     => array(utf8_encode($asiakas['toimitus_osoite'])),
        'postcode'   => utf8_encode($asiakas['toimitus_postino']),
        'city'       => utf8_encode($asiakas['toimitus_postitp']),
        'country_id' => utf8_encode($asiakas['maa']),
        'telephone'  => utf8_encode($asiakas['yhenk_puh']),
        'company'    => utf8_encode($asiakas['nimi']),
        'is_default_shipping' => true
      );

      if (count($asiakkaat_erikoisparametrit) > 0) {
        foreach ($asiakkaat_erikoisparametrit as $erikoisparametri) {
          $key = $erikoisparametri['nimi'];
          $value = $erikoisparametri['arvo'];

          // Jos value l�ytyy asiakas-arraysta, k�ytet��n sit�
          if (isset($asiakas[$value])) {
            $asiakas_data[$key] = utf8_encode($asiakas[$value]);
            $laskutus_osoite_data[$key] = utf8_encode($asiakas[$value]);
            $toimitus_osoite_data[$key] = utf8_encode($asiakas[$value]);
          }
        }
      }

      // Lis�t��n tai p�ivitet��n asiakas

      // Jos asiakasta ei ole olemassa (sill� ei ole pupessa magento_tunnus:ta) niin lis�t��n se
      if (empty($asiakas['magento_tunnus'])) {
        try {
          $result = $this->_proxy->call(
            $this->_session,
            'customer.create',
            array(
              $asiakas_data
            )
          );

          $this->log('magento_asiakkaat', "Asiakas lis�tty ({$result})");
          $this->debug('magento_asiakkaat', $asiakas_data);
          $asiakas['magento_tunnus'] = $result;

          // P�ivitet��n magento_tunnus pupeen
          $query = "UPDATE yhteyshenkilo
                    SET ulkoinen_asiakasnumero = '{$asiakas['magento_tunnus']}'
                    WHERE yhtio      = '{$asiakas['yhtio']}'
                    AND liitostunnus = '{$asiakas['tunnus']}'
                    AND rooli        = 'Magento'
                    AND tunnus       = '{$asiakas['yhenk_tunnus']}'";
          pupe_query($query);

        }
        catch (Exception $e) {
          $this->_error_count++;
          $this->log('magento_asiakkaat', "Virhe! Asiakkaan lis�ys ep�onnistui", $e);
          $this->debug('magento_asiakkaat', $asiakas_data);
        }
      }
      // Asiakas on jo olemassa, p�ivitet��n
      else {
        try {
          $poista_asiakas_defaultit = $this->_magento_poista_asiakasdefaultit;

          // Jos halutaan ohittaa asiakasparametreja, poistetaan ne ennen paivitysta
          if (count($poista_asiakas_defaultit) > 0) {
            foreach ($poista_asiakas_defaultit as $poistettava_key) {
              unset($asiakas_data[$poistettava_key]);
            }
          }

          $result = $this->_proxy->call(
            $this->_session,
            'customer.update',
            array(
              $asiakas['magento_tunnus'],
              $asiakas_data
            )
          );

          $this->log('magento_asiakkaat', "Asiakas p�ivitetty ({$asiakas['magento_tunnus']})");
          $this->debug('magento_asiakkaat', $asiakas_data);

          // L�hetet��n aktivointiviesti Magentoon jos ominaisuus on p��ll� sek� yhteyshenkil�lle
          // on merkattu magentokuittaus
          if ($this->_asiakkaan_aktivointi and $this->aktivoidaankoAsiakas($asiakas['tunnus'], $asiakas['magento_tunnus'])) {
            $result = $this->asiakkaanAktivointi($asiakas['yhtio'], $asiakas['yhenk_tunnus']);

            if ($result) {
              $this->log('magento_asiakkaat', "Yhteyshenkil�n: '{$asiakas['yhenk_tunnus']}' Magentoasiakas: {$asiakas['magento_tunnus']} aktivoitu");
              $this->debug('magento_asiakkaat', $asiakas_data);
            }
          }
        }
        catch (Exception $e) {
          $this->_error_count++;
          $this->log('magento_asiakkaat', "Virhe! Asiakkaan p�ivitys ep�onnistui", $e);
          $this->debug('magento_asiakkaat', $asiakas_data);
        }
      }

      try {
        // Haetaan ensin asiakkaan laskutus- ja toimitusosoitteet
        $address_array = $this->_proxy->call(
          $this->_session,
          'customer_address.list',
          $asiakas['magento_tunnus']
        );

        // Ja poistetaan ne
        if (count($address_array) > 0) {
          foreach ($address_array as $address) {
            $result = $this->_proxy->call(
              $this->_session, 'customer_address.delete', $address['customer_address_id']
            );
          }
        }

      }
      catch (Exception $e) {
        $this->log('magento_asiakkaat', "Virhe! Asiakkaan '{$asiakas['tunnus']}' osoitteiden haku ep�onnistui, Magento tunnus {$asiakas['magento_tunnus']}", $e);
        $this->_error_count++;
      }

      if (isset($laskutus_osoite_data['firstname']) and !empty($laskutus_osoite_data['firstname'])) {
        try {
          // Lis�t��n laskutusosoite
          $result = $this->_proxy->call(
            $this->_session,
            'customer_address.create',
            array(
              'customerId'  => $asiakas['magento_tunnus'],
              'addressdata' => ($laskutus_osoite_data)
            )
          );
        }
        catch (Exception $e) {
          $this->log('magento_asiakkaat', "Virhe! Asiakkaan laskutusosoitteen p�ivitys ep�onnistui", $e);
          $this->debug('magento_asiakkaat', $laskutus_osoite_data);
          $this->_error_count++;
        }
      }

      if (isset($toimitus_osoite_data['firstname']) and !empty($toimitus_osoite_data['firstname'])) {
        try {
          // Lis�t��n toimitusosoite
          $result = $this->_proxy->call(
            $this->_session,
            'customer_address.create',
            array(
              'customerId' => $asiakas['magento_tunnus'],
              'addressdata' => ($toimitus_osoite_data)
            )
          );
        }
        catch (Exception $e) {
          $this->log('magento_asiakkaat', "Virhe! Asiakkaan toimitusosoitteen p�ivitys ep�onnistui", $e);
          $this->debug('magento_asiakkaat', $toimitus_osoite_data);
          $this->_error_count++;
        }
      }
    }

    $this->log('magento_asiakkaat', "$count asiakasta p�ivitetty");

    // Palautetaan p�vitettyjen asiakkaiden m��r�
    return $count;
  }

  public function setTaxClassID($tax_class_id) {
    $this->_tax_class_id = $tax_class_id;
  }

  public function setParentID($parent_id) {
    $this->_parent_id = $parent_id;
  }

  public function setHintakentta($hintakentta) {
    $this->_hintakentta = $hintakentta;
  }

  public function setKategoriat($magento_kategoriat) {
    $this->_kategoriat = $magento_kategoriat;
  }

  public function setCategoryaccesscontrol($categoryaccesscontrol) {
    $this->_categoryaccesscontrol = $categoryaccesscontrol;
  }

  public function setConfigurableNimityskentta($configurable_tuote_nimityskentta) {
    $this->_configurable_tuote_nimityskentta = $configurable_tuote_nimityskentta;
  }

  public function setConfigurableLapsituoteNakyvyys($configurable_lapsituote_nakyvyys) {
    $this->_configurable_lapsituote_nakyvyys = $configurable_lapsituote_nakyvyys;
  }

  public function setVerkkokauppatuotteetErikoisparametrit($verkkokauppatuotteet_erikoisparametrit) {
    $this->_verkkokauppatuotteet_erikoisparametrit = $verkkokauppatuotteet_erikoisparametrit;
  }

  public function setTuetutKieliversiot($tuetut_kieliversiot) {
    $this->_TuetutKieliversiot = $tuetut_kieliversiot;
  }

  public function setAsiakkaatErikoisparametrit($asiakkaat_erikoisparametrit) {
    $this->_asiakkaat_erikoisparametrit = $asiakkaat_erikoisparametrit;
  }

  public function setStickyKategoriat($magento_sticky_kategoriat) {
    $this->_sticky_kategoriat = $magento_sticky_kategoriat;
  }

  public function setSisaanluvunEsto($sisaanluvun_esto) {
    $this->_sisaanluvun_esto = $sisaanluvun_esto;
  }

  public function setUniversalTuoteryhma($universal_tuoteryhma) {
    $this->_universal_tuoteryhma = $universal_tuoteryhma;
  }

  public function setAsiakasAktivointi($asiakas_aktivointi) {
    $tila = $asiakas_aktivointi ? $asiakas_aktivointi : false;
    $this->_asiakkaan_aktivointi = $tila;
  }

  public function setAsiakaskohtaisetTuotehinnat($asiakaskohtaiset_tuotehinnat) {
    $tila = $asiakaskohtaiset_tuotehinnat ? $asiakaskohtaiset_tuotehinnat : false;
    $this->_asiakaskohtaiset_tuotehinnat = $tila;
  }

  public function setPoistaDefaultTuoteparametrit(array $poistettavat) {
    $this->_magento_poistadefaultit = $poistettavat;
  }

  public function setPoistaDefaultAsiakasparametrit(array $poistettavat_asiakasparamit) {
    $this->_magento_poista_asiakasdefaultit = $poistettavat_asiakasparamit;
  }

  public function setUrlKeyAttributes(array $url_key_attributes) {
    $this->_magento_url_key_attributes = $url_key_attributes;
  }

  public function setRemoveProducts($value) {
    $this->_magento_poista_tuotteita = $value;
  }

  public function set_magento_lisaa_tuotekuvat($value) {
    $this->magento_lisaa_tuotekuvat = $value;
  }

  public function set_magento_fetch_order_status($value) {
    $this->magento_fetch_order_status = $value;
  }

  public function set_magento_perusta_disabled($value) {
    $this->magento_perusta_disabled = $value;
  }

  public function set_magento_nimitykseen_parametrien_arvot($value) {
    $this->magento_nimitykseen_parametrien_arvot = $value;
  }

  public function set_magento_simple_tuote_nimityskentta($value) {
    $this->magento_simple_tuote_nimityskentta = $value;
  }

  public function set_ovt_tunnus($value) {
    $this->ovt_tunnus = $value;
  }

  public function set_pupesoft_tilaustyyppi($value) {
    $this->pupesoft_tilaustyyppi = $value;
  }

  public function set_rahtikulu_nimitys($value) {
    $this->rahtikulu_nimitys = $value;
  }

  public function set_rahtikulu_tuoteno($value) {
    $this->rahtikulu_tuoteno = $value;
  }

  public function set_verkkokauppa_asiakasnro($value) {
    $this->verkkokauppa_asiakasnro = $value;
  }

  public function set_edi_polku($value) {
    if (!is_writable($value)) {
      throw new Exception("EDI -hakemistoon ei voida kirjoittaa");
    }

    $this->edi_polku = $value;
  }

  public function set_magento_maksuehto_ohjaus($value) {
    $this->magento_maksuehto_ohjaus = $value;
  }

  public function set_magento_erikoiskasittely($value) {
    $this->magento_erikoiskasittely = $value;
  }

  public function set_configurable_tuotetiedot($value) {
    $this->configurable_tuotetiedot = $value;
  }

  public function set_magento_tuote_aina_varastossa($value) {
    $this->tuote_aina_varastossa = $value;
  }

  // Hakee error_countin:n
  public function getErrorCount() {
    return $this->_error_count;
  }

  // Kuittaa asiakkaan aktivoiduksi Magentossa
  // HUOM! Vaatii r��t�l�idyn Magenton
  private function asiakkaanAktivointi($yhtio, $yhteyshenkilon_tunnus) {
    $reply = false;

    // Haetaan yhteyshenkil�n tiedot
    try {
      $query = "SELECT
                email,
                ulkoinen_asiakasnumero id
                FROM
                yhteyshenkilo
                WHERE yhtio                 = '{$yhtio}'
                AND rooli                   = 'Magento'
                AND tunnus                  = '{$yhteyshenkilon_tunnus}'
                AND email                  != ''
                AND ulkoinen_asiakasnumero != ''
                LIMIT 1";
      $result = pupe_query($query);
      $yhenkrow = mysql_fetch_assoc($result);

      // Aktivoidaan asiakas Magentoon
      $reply = $this->_proxy->call(
        $this->_session,
        'activate_customer.activateBusinessCustomer',
        array(
          $yhenkrow['email'],
          $this->_asiakkaan_aktivointi
        )
      );

      // Merkataan aktivointikuittaus tehdyksi
      $putsausquery = "UPDATE yhteyshenkilo
                       SET aktivointikuittaus = ''
                       WHERE yhtio = '{$yhtio}'
                       AND tunnus  = '{$yhteyshenkilon_tunnus}'";
      pupe_query($putsausquery);
    }
    catch (Exception $e) {
      $this->_error_count++;
      $this->log('magento_asiakkaat', "Virhe! Asiakkaan aktivointi ep�onnistui.", $e);
    }

    return $reply;
  }

  // Hakee ja siirt�� tuotteen asiakaskohtaiset hinnat Magentoon
  // HUOM! Vaatii r��t�l�idyn Magenton
  private function lisaaAsiakaskohtaisetTuotehinnat($tuotenumero, $magento_tuotenumero) {
    global $kukarow;

    $reply = false;
    $asiakaskohtainenhintadata = array();

    // Haetaan Pupesta kaikki Magento-asiakkaat ja n�iden yhteyshenkil�t
    $asiakkaat_per_yhteyshenkilo = $this->hae_magentoasiakkaat_ja_yhteyshenkilot();

    if ($asiakkaat_per_yhteyshenkilo === false) {
      return false;
    }

    // Ensin poistetaan tuotteen asiakashinnat Magentosta kaikki kerralla
    $this->poista_tuotteen_asiakaskohtaiset_hinnat($asiakkaat_per_yhteyshenkilo, $magento_tuotenumero, true);

    // Sitten haetaan asiakaskohtainen hintadata Pupesta
    $asiakaskohtainenhintadata = $this->hae_tuotteen_asiakaskohtaiset_hinnat($asiakkaat_per_yhteyshenkilo, $tuotenumero);

    // Lopuksi siirret��n tuotteen kaikki asiakaskohtaiset hinnat Magentoon
    if ($asiakaskohtainenhintadata === false) {
      return false;
    }

    $current = 0;
    $total = count($asiakaskohtainenhintadata);
    $onnistuiko_lisays = true;
    $offset = 0;

    while ($hintadata = array_slice($asiakaskohtainenhintadata, $offset, 300)) {
      try {
        $reply = $this->_proxy->call(
          $this->_session,
          'price_per_customer.setPriceForCustomersPerProduct',
          array($magento_tuotenumero, $hintadata)
        );

        $this->log('magento_tuotteet', "({$offset}/{$total}): Tuotteen {$magento_tuotenumero} asiakaskohtaiset hinnat lis�tty. Block size 300");
        $this->debug('magento_tuotteet', $hintadata);
        $offset += 300;
      }
      catch (Exception $e) {
        $this->_error_count++;
        $this->log('magento_tuotteet', "Virhe! Tuotteen {$magento_tuotenumero} asiakaskohtaisen ({$hintadata['customerEmail']}) hinnan lis�ys ep�onnistui blockina", $e);
        $onnistuiko_lisays = false;
        break;
      }
      $onnistuiko_lisays = true;
    }
    
    if ($onnistuiko_lisays === false) {
      $current = $offset;
      for ($i = $offset; $i <= $total; $i++) {
        $hintadata = $asiakaskohtainenhintadata[$i];
        $current++;
        try {
          $reply = $this->_proxy->call(
            $this->_session,
            'price_per_customer.setPriceForCustomersPerProduct',
            array($magento_tuotenumero, array($hintadata))
          );
      
          $this->log('magento_tuotteet', "({$current}/{$i}/{$total}): Tuotteen {$magento_tuotenumero} asiakaskohtaiset ({$hintadata['customerEmail']}) hinnat lis�tty");
          $this->debug('magento_tuotteet', $hintadata);
        }
        catch (Exception $e) {
          $this->_error_count++;
          $this->log('magento_tuotteet', "Virhe! Tuotteen {$magento_tuotenumero} asiakaskohtaisen ({$hintadata['customerEmail']}) hinnan lis�ys ep�onnistui", $e, $i);
        }
      }
    }
    return $reply;
  }

  private function hae_magentoasiakkaat_ja_yhteyshenkilot() {
    global $kukarow, $yhtiorow;

    $yhtio = $kukarow['yhtio'];
    $asiakkaat_per_yhteyshenkilo = array();

    $query = "SELECT asiakas.tunnus asiakastunnus,
              asiakas.ytunnus,
              yhteyshenkilo.email asiakas_email,
              yhteyshenkilo.ulkoinen_asiakasnumero,
              asiakas.ryhma
              FROM yhteyshenkilo
              JOIN asiakas ON (yhteyshenkilo.yhtio = asiakas.yhtio
                AND yhteyshenkilo.liitostunnus            = asiakas.tunnus)
              WHERE yhteyshenkilo.yhtio                   = '{$yhtio}'
                AND yhteyshenkilo.rooli                   = 'Magento'
                AND yhteyshenkilo.email                  != ''
                AND yhteyshenkilo.ulkoinen_asiakasnumero != ''
              ORDER BY yhteyshenkilo.muutospvm";
    $result = pupe_query($query);

    while ($rivi = mysql_fetch_assoc($result)) {
      $asiakasdata = array(
        'asiakastunnus'         => $rivi['asiakastunnus'],
        'asiakas_email'         => $rivi['asiakas_email'],
        'magento_asiakastunnus' => $rivi['ulkoinen_asiakasnumero'],
        'ytunnus'               => $rivi['ytunnus'],
        'ryhma'                 => $rivi['ryhma']
      );
      $asiakkaat_per_yhteyshenkilo[] = $asiakasdata;
    }

    if (count($asiakkaat_per_yhteyshenkilo) < 1) {
      return false;
    }

    return $asiakkaat_per_yhteyshenkilo;
  }

  private function poista_tuotteen_asiakaskohtaiset_hinnat(Array $asiakkaat_per_yhteyshenkilo, $magento_tuotenumero, $kaikki_kerralla = false) {
    $current = 0;
    $total = count($asiakkaat_per_yhteyshenkilo);
    $asiakashinnat = array();
    $onnistuiko_paivitys = true;

    if ($kaikki_kerralla) {
      // Poistetaan kaikkien asiakkaiden hinta t�lt� tuotteelta
      $offset = 0;
      foreach ($asiakkaat_per_yhteyshenkilo as $asiakas) {
        $asiakashinnat[] = array(
          'customerEmail' => $asiakas['asiakas_email'],
          'websiteCode' => $this->_asiakaskohtaiset_tuotehinnat,
          'delete' => 1
        );
      }

      // Pilkotaan array blockeihin ett� Magento ei hirt� kiinni
      while ($asiakashinta = array_slice($asiakashinnat, $offset, 500)) {
        try {
          $this->_proxy->call(
            $this->_session,
            'price_per_customer.setPriceForCustomersPerProduct',
            array($magento_tuotenumero, $asiakashinta)
          );

          $this->log('magento_tuotteet', "({$offset}/{$total}) Tuotteen {$magento_tuotenumero} kaikki asiakaskohtaiset hinnat poistettu. Block size 500");
          $offset += 500;
        }
        catch(Exception $e) {
          $this->_error_count++;
          $this->log('magento_tuotteet', "Virhe asiakaskohtaisten hintojen poistossa! Magento-tuoteno {$magento_tuotenumero}, website-code: {$this->_asiakaskohtaiset_tuotehinnat}", $e);
          $onnistuiko_paivitys = false;
          break;
        }
      }
    }
    if ($onnistuiko_paivitys === false) {
      $current = $offset;
      // Poistetaan kaikkien asiakkaiden hinta t�lt� tuotteelta
      for ($i = $offset; $i <= $total; $i++) {
        $asiakashinta = $asiakashinnat[$i];
        $current++;

        try {
          $this->_proxy->call(
            $this->_session,
            'price_per_customer.setPriceForCustomersPerProduct',
            array($magento_tuotenumero, array($asiakashinta))
          );

          $this->log('magento_tuotteet', "({$current}/{$total}): Tuotteen {$magento_tuotenumero} asiakaskohtaiset hinnat poistettu ({$asiakashinta['asiakas_email']})");
        }
        catch(Exception $e) {
          $this->_error_count++;
          $this->log('magento_tuotteet', "Virhe asiakaskohtaisten hintojen poistossa! Magento-tuoteno {$magento_tuotenumero}, asiakas_email: {$asiakashintarivi['asiakas_email']}, website-code: {$this->$asiakashinta}", $e);
        }
      }

      return true;
    }
  }

  private function hae_tuotteen_asiakaskohtaiset_hinnat($asiakkaat_per_yhteyshenkilo, $tuotenumero) {
    global $kukarow;

    // Haetaan annettujen Magentoasiakkaiden hinnat annetulle tuotteelle
    $asiakaskohtaiset_hinnat_data = array();

    // Haetaan tuotteen tiedot
    $query = "SELECT *
              FROM tuote
              WHERE yhtio = '{$kukarow['yhtio']}'
              AND tuoteno = '{$tuotenumero}'";
    $result = pupe_query($query);
    $tuoterow = mysql_fetch_assoc($result);

    $tuotteen_vertailuhinta = $tuoterow['myyntihinta'];
    $asiakashinnat = array();

    $query = "SELECT ";

    foreach ($asiakkaat_per_yhteyshenkilo as $asiakas) {
      // Tuotteen hinta t�lle asiakkaalle
      $hinta = 0;
      $kpl = 1;
      unset($row);

      $query = "(SELECT '1' prio, hinta, laji, IFNULL(TO_DAYS(current_date)-TO_DAYS(alkupvm),9999999999999) aika, minkpl, valkoodi, tunnus
                 FROM asiakashinta ashin1 USE INDEX (yhtio_asiakas_tuoteno)
                 WHERE yhtio  = '$kukarow[yhtio]'
                 and asiakas  = '$asiakas[asiakastunnus]'
                 and asiakas  > 0
                 and tuoteno  = '$tuoterow[tuoteno]'
                 and tuoteno != ''
                 and (minkpl <= $kpl or minkpl = 0)
                 and ((alkupvm <= current_date and if (loppupvm = '0000-00-00','9999-12-31',loppupvm) >= current_date) or (alkupvm='0000-00-00' and loppupvm='0000-00-00')))
                 UNION
                 (SELECT '2' prio, hinta, laji, IFNULL(TO_DAYS(current_date)-TO_DAYS(alkupvm),9999999999999) aika, minkpl, valkoodi, tunnus
                 FROM asiakashinta ashin2 USE INDEX (yhtio_ytunnus_tuoteno)
                 WHERE yhtio  = '$kukarow[yhtio]'
                 and ytunnus  = '$asiakas[ytunnus]'
                 and ytunnus != ''
                 and tuoteno  = '$tuoterow[tuoteno]'
                 and tuoteno != ''
                 and (minkpl <= $kpl or minkpl = 0)
                 and ((alkupvm <= current_date and if (loppupvm = '0000-00-00','9999-12-31',loppupvm) >= current_date) or (alkupvm='0000-00-00' and loppupvm='0000-00-00')))
                 ORDER BY prio, minkpl desc, aika, valkoodi DESC, tunnus desc
                 LIMIT 1";
      $result = pupe_query($query);

      if (mysql_num_rows($result) > 0) {
        $row = mysql_fetch_assoc($result);
      }

      if (!isset($row)) {
        $query = "(SELECT '1' prio, hinta, laji, IFNULL(TO_DAYS(current_date)-TO_DAYS(alkupvm),9999999999999) aika, minkpl, valkoodi, tunnus
                   FROM asiakashinta ashin1 USE INDEX (yhtio_asiakas_ryhma)
                   WHERE yhtio  = '$kukarow[yhtio]'
                   and asiakas  = '$asiakas[asiakastunnus]'
                   and asiakas  > 0
                   and ryhma    = '$tuoterow[aleryhma]'
                   and ryhma   != ''
                   and (minkpl <= $kpl or minkpl = 0)
                   and ((alkupvm <= current_date and if (loppupvm = '0000-00-00','9999-12-31',loppupvm) >= current_date) or (alkupvm='0000-00-00' and loppupvm='0000-00-00')))
                   UNION
                   (SELECT '2' prio, hinta, laji, IFNULL(TO_DAYS(current_date)-TO_DAYS(alkupvm),9999999999999) aika, minkpl, valkoodi, tunnus
                   FROM asiakashinta ashin2 USE INDEX (yhtio_ytunnus_ryhma)
                   WHERE yhtio  = '$kukarow[yhtio]'
                   and ytunnus  = '$asiakas[ytunnus]'
                   and ytunnus != ''
                   and ryhma    = '$tuoterow[aleryhma]'
                   and ryhma   != ''
                   and (minkpl <= $kpl or minkpl = 0)
                   and ((alkupvm <= current_date and if (loppupvm = '0000-00-00','9999-12-31',loppupvm) >= current_date) or (alkupvm='0000-00-00' and loppupvm='0000-00-00')))
                   ORDER BY prio, minkpl desc, aika, valkoodi DESC, tunnus desc
                   LIMIT 1";
        $result = pupe_query($query);

        if (mysql_num_rows($result) > 0) {
          $row = mysql_fetch_assoc($result);
        }
      }

      if (!isset($row)) {
        $query = "(SELECT '1' prio, alennus, alennuslaji, minkpl, IFNULL(TO_DAYS(CURRENT_DATE)-TO_DAYS(alkupvm),9999999999999) aika, tunnus
                   FROM asiakasalennus asale1 USE INDEX (yhtio_asiakas_tuoteno)
                   WHERE yhtio  = '$kukarow[yhtio]'
                   AND asiakas  = '$asiakas[asiakastunnus]'
                   AND asiakas  > 0
                   AND tuoteno  = '$tuoterow[tuoteno]'
                   AND tuoteno != ''
                   AND (minkpl = 0 OR (minkpl <= $kpl AND monikerta = '') OR (MOD($kpl, minkpl) = 0 AND monikerta != ''))
                   AND ((alkupvm <= CURRENT_DATE AND IF (loppupvm = '0000-00-00','9999-12-31',loppupvm) >= CURRENT_DATE) OR (alkupvm='0000-00-00' AND loppupvm='0000-00-00'))
                   AND alennus  >= 0
                   AND alennus  <= 100)
                   UNION
                   (SELECT '2' prio, alennus, alennuslaji, minkpl, IFNULL(TO_DAYS(CURRENT_DATE)-TO_DAYS(alkupvm),9999999999999) aika, tunnus
                   FROM asiakasalennus asale2 USE INDEX (yhtio_ytunnus_tuoteno)
                   WHERE yhtio  = '$kukarow[yhtio]'
                   AND ytunnus  = '$asiakas[ytunnus]'
                   AND ytunnus != ''
                   AND tuoteno  = '$tuoterow[tuoteno]'
                   AND tuoteno != ''
                   AND (minkpl = 0 OR (minkpl <= $kpl AND monikerta = '') OR (MOD($kpl, minkpl) = 0 AND monikerta != ''))
                   AND ((alkupvm <= CURRENT_DATE AND IF (loppupvm = '0000-00-00','9999-12-31',loppupvm) >= CURRENT_DATE) OR (alkupvm='0000-00-00' AND loppupvm='0000-00-00'))
                   AND alennus  >= 0
                   AND alennus  <= 100)
                   ORDER BY alennuslaji, prio, minkpl DESC, aika, alennus DESC, tunnus DESC
                   LIMIT 1";
        $result = pupe_query($query);

        if (mysql_num_rows($result) > 0) {
          $row = mysql_fetch_assoc($result);
        }
      }

      if (!isset($row)) {
        $query = "(SELECT '1' prio, alennus, alennuslaji, minkpl, IFNULL(TO_DAYS(CURRENT_DATE)-TO_DAYS(alkupvm),9999999999999) aika, tunnus
                   FROM asiakasalennus asale1 USE INDEX (yhtio_asiakas_ryhma)
                   WHERE yhtio  = '$kukarow[yhtio]'
                   AND asiakas  = '$asiakas[asiakastunnus]'
                   AND asiakas  > 0
                   AND ryhma    = '$tuoterow[aleryhma]'
                   AND ryhma   != ''
                   AND (minkpl = 0 OR (minkpl <= $kpl AND monikerta = '') OR (MOD($kpl, minkpl) = 0 AND monikerta != ''))
                   AND ((alkupvm <= CURRENT_DATE AND IF (loppupvm = '0000-00-00','9999-12-31',loppupvm) >= CURRENT_DATE) OR (alkupvm='0000-00-00' AND loppupvm='0000-00-00'))
                   AND alennus  >= 0
                   AND alennus  <= 100)
                   UNION
                   (SELECT '2' prio, alennus, alennuslaji, minkpl, IFNULL(TO_DAYS(CURRENT_DATE)-TO_DAYS(alkupvm),9999999999999) aika, tunnus
                   FROM asiakasalennus asale2 USE INDEX (yhtio_ytunnus_ryhma)
                   WHERE yhtio  = '$kukarow[yhtio]'
                   AND ytunnus  = '$asiakas[ytunnus]'
                   AND ytunnus != ''
                   AND ryhma    = '$tuoterow[aleryhma]'
                   AND ryhma   != ''
                   AND (minkpl = 0 OR (minkpl <= $kpl AND monikerta = '') OR (MOD($kpl, minkpl) = 0 AND monikerta != ''))
                   AND ((alkupvm <= CURRENT_DATE AND IF (loppupvm = '0000-00-00','9999-12-31',loppupvm) >= CURRENT_DATE) OR (alkupvm='0000-00-00' AND loppupvm='0000-00-00'))
                   AND alennus  >= 0
                   AND alennus  <= 100)
                   ORDER BY alennuslaji, prio, minkpl DESC, aika, alennus DESC, tunnus desc
                   LIMIT 1";
        $result = pupe_query($query);

        if (mysql_num_rows($result) > 0) {
          $row = mysql_fetch_assoc($result);
        }
      }

      if (!isset($row)) {
        $query = "SELECT '1' prio, alennus, alennuslaji, minkpl, IFNULL(TO_DAYS(CURRENT_DATE)-TO_DAYS(alkupvm),9999999999999) aika, tunnus
                   FROM asiakasalennus asale1 USE INDEX (yhtio_asiakas_ryhma)
                   WHERE yhtio  = '$kukarow[yhtio]'
                   AND asiakas_ryhma  = '$asiakas[ryhma]'
                   AND asiakas_ryhma  > 0
                   AND ryhma    = '$tuoterow[aleryhma]'
                   AND ryhma   != ''
                   AND (minkpl = 0 OR (minkpl <= $kpl AND monikerta = '') OR (MOD($kpl, minkpl) = 0 AND monikerta != ''))
                   AND ((alkupvm <= CURRENT_DATE AND IF (loppupvm = '0000-00-00','9999-12-31',loppupvm) >= CURRENT_DATE) OR (alkupvm='0000-00-00' AND loppupvm='0000-00-00'))
                   AND alennus  >= 0
                   AND alennus  <= 100
                   ORDER BY alennuslaji, prio, minkpl DESC, aika, alennus DESC, tunnus desc
                   LIMIT 1";
        $result = pupe_query($query);

        if (mysql_num_rows($result) > 0) {
          $row = mysql_fetch_assoc($result);
        }
      }

      // Asetetaan hintamuuttujaan joko:
      if (isset($row)) {
        // l�ydetty asiakashinta
        if (isset($row['hinta'])) {
          $hinta = $row['hinta'];
        }

        // tai lasketaan alennus pois myyntihinnasta
        if (isset($row['alennus'])) {
          $hinta = $tuoterow['myyntihinta'];
          $kokonaisale = (1 - $row['alennus'] / 100);
          $hinta = round(($hinta * $kokonaisale), 2);
        }
      }

      if ($hinta > 0 and $hinta <> $tuotteen_vertailuhinta) {
        $asiakaskohtaiset_hinnat_data[] = array(
          'customerEmail' => $asiakas['asiakas_email'],
          'websiteCode'   => $this->_asiakaskohtaiset_tuotehinnat,
          'price'         => $hinta,
          'delete'        => 0
        );
      }
    }

    if (count($asiakaskohtaiset_hinnat_data) == 0) {
      return false;
    }

    return $asiakaskohtaiset_hinnat_data;
  }

  // Tarkistaa onko t�m� asiakkaan yhteyshenkil� merkattu kuitattavaksi
  private function aktivoidaankoAsiakas($asiakastunnus, $asiakkaan_magentotunnus) {
    global $kukarow;

    $query = "SELECT yhteyshenkilo.aktivointikuittaus tieto
              FROM yhteyshenkilo
              JOIN asiakas ON (yhteyshenkilo.yhtio = asiakas.yhtio
                AND yhteyshenkilo.liitostunnus           = asiakas.tunnus
                AND asiakas.tunnus                       = '{$asiakastunnus}')
              WHERE yhteyshenkilo.yhtio                  = '{$kukarow['yhtio']}'
                AND yhteyshenkilo.ulkoinen_asiakasnumero = '{$asiakkaan_magentotunnus}'";
    $result = pupe_query($query);
    $vastausrivi = mysql_fetch_assoc($result);

    $vastaus = !empty($vastausrivi['tieto']);

    return $vastaus;
  }

  // Hakee verkkokaupan tuotteet
  // NOTE: eclude giftcards not supported!
  private function getProductList($only_skus = false, $exclude_giftcards = false) {
    assert($exclude_giftcards == false);
    $this->log('magento_tuotteet', "Haetaan tuotenumerot Magentosta");
   
    // all products
    $searchCriteria = [
      'searchCriteria' => [
          'filterGroups' => [[]],
      ],
    ];
    
    try {
      $soap_result = $this->get_client()->catalogProductRepositoryV1GetList($searchCriteria);
      
      $products = $soap_result->result->items->item;
     /*  this propably does not work in M2
     if ($exclude_giftcards) {
        foreach ($result as $index => $product) {
          if ($product['type'] == 'giftcards') {
            unset($result[$index]);
          }
        }
      }
      */

      $this->log('magento_tuotteet', "Haettiin ".count($products)." tuotetta");

      if ($only_skus == true) {
        $skus = array();

        foreach ($products as $product) {
          $skus[] = $product->sku;
        }

        return $skus;
      }

      return $products;
    }
    catch (Exception $e) {
      $this->_error_count++;
      $this->log('magento_tuotteet', "Virhe! Tuotelistan hakemisessa", $e);
      return null;
    }
  }

  // Rakentaa url_key:n tuotteelle
  private function getUrlKeyForProduct($tuotedata) {
    $halutut_array = $this->_magento_url_key_attributes;
    $url_key = $this->sanitize_link_rewrite($tuotedata['nimi']);
    $parametrit = array();

    if (!empty($tuotedata['tuotteen_parametrit']['fi'])) {
      foreach ($tuotedata['tuotteen_parametrit']['fi'] as $parametri) {
        $key = $parametri['option_name'];
        $value = $parametri['arvo'];
        $parametrit[$key] = $value;
      }
    }

    foreach ($halutut_array as $key => $value) {
      if (!empty($parametrit[$value])) {
        $safe_part1 = $this->sanitize_link_rewrite($value);
        $safe_part2 = $this->sanitize_link_rewrite($parametrit[$value]);
        $url_key .= "-{$safe_part1}-{$safe_part2}";
      }
    }

    return utf8_encode($url_key);
  }

  // Sanitizes string for magento url_key column
  private function sanitize_link_rewrite($string) {
    return preg_replace('/[^a-zA-Z0-9_]/', '', $string);
  }

  // Palauttaa syvimm�n kategoria id:n annetusta tuotteen koko tuotepolusta
  private function createCategoryTree($ancestors) {
    $cat_id = $this->_parent_id;

    foreach ($ancestors as $nimi) {
      $cat_id = $this->createSubCategory($nimi, $cat_id);
    }

    return $cat_id;
  }

  // Lis�� tuotepuun kategorian annettun category_id:n alle, jos sellaista ei ole jo olemassa
  private function createSubCategory($name, $parent_cat_id) {
    // otetaan koko tuotepuu, valitaan siit� eka solu idn perusteella
    // sen lapsista etsit��n nime�, jos ei l�ydy, luodaan viimeisimm�n idn alle
    // lopuksi palautetaan id
    $name = utf8_encode($name);
    $categoryaccesscontrol = $this->_categoryaccesscontrol;
    $magento_tree = $this->getCategories();
    $results = $this->getParentArray($magento_tree, "$parent_cat_id");

    if (empty($results)) {
     $this->log('magento_tuotteet', 'Virhe! Kategoria-array on tyhj�.');

     return 0;
    }

    // Etsit��n kategoriaa
    foreach ($results[0]['children'] as $k => $v) {
      if (strcasecmp($name, $v['name']) == 0) {
        return $v['category_id'];
      }
    }

    // Lis�t��n kategoria, jos ei l�ytynyt
    $category_data = array(
      'name'                  => $name,
      'is_active'             => 1,
      'position'              => 1,
      'default_sort_by'       => 'position',
      'available_sort_by'     => 'position',
      'include_in_menu'       => 1,
      'is_anchor'             => 1
    );

    if ($categoryaccesscontrol) {
      // HUOM: Vain jos "Category access control"-moduli on asennettu
      $category_data['accesscontrol_show_group'] = 0;
    }

    // Kutsutaan soap rajapintaa
    try {
      $category_id = $this->_proxy->call(
        $this->_session,
        'catalog_category.create',
        array($parent_cat_id, $category_data)
      );
    }
    catch (Exception $e) {
      $this->_error_count++;
      $this->log('magento_tuotteet', "Virhe! Kategorian perustus ep�onnistui", $e);

      return 0;
    }

    $this->log('magento_tuotteet', "Lis�ttiin tuotepuun kategoria:$name tunnuksella: $category_id");

    unset($this->_category_tree);

    return $category_id;
  }

  // Etsii arraysta key->value paria, ja jos l�ytyy niin palauttaa sen
  private function getParentArray($tree, $parent_cat_id) {
    //etsit��n keyt� "category_id" valuella isatunnus ja return sen lapset
    return search_array_key_for_value_recursive($tree, 'category_id', $parent_cat_id);
  }

  // Hakee oletus attribuuttisetin Magentosta
  //oletus attribuuttien hakeminen otettu pois käytöstä, ID hardcoded
  // private function getAttributeSet() {
  //   if (empty($this->_attributeSet)) {
  //     $attributeSets = $this->_proxy->call($this->_session, 'product_attribute_set.list');
  //     $this->_attributeSet = current($attributeSets);
  //   }

  //   return $this->_attributeSet;
  // }

  // Hakee tuotteelle attribute set id:n
  private function get_attribute_set_id(Array $tuote) {
    $pupesoft_attr_id = $this->get_tuotteen_avainsana($tuote, 'magento_attribute_set_id');

    if (is_null($pupesoft_attr_id)) {
      $set_id = 31; //ID for default product attribute set
    }
    else {
      $set_id = $pupesoft_attr_id['selite'];
    }

    $this->log('magento_tuotteet', "Attribute set {$set_id}");

    return $set_id;
  }

  // Hakee tuotteen avainsanan arvon
  private function get_tuotteen_avainsana(Array $tuote, $laji, $kieli = 'fi') {
    // loopataan l�pi tuotteen avainsanat ja palautetaan kysytty avainsana jos l�ytyy
    foreach ($tuote['tuotteen_avainsanat'] as $avainsana) {
      if ($avainsana['laji'] == $laji and $avainsana['kieli'] == $kieli) {
        return $avainsana;
      }
    }

    return null;
  }

  // Hakee kaikki attribuutit magentosta
  private function getAttributeList($attribute_set_id) {
    // memoization
    if (!is_null($this->magento_attribute_list)) {
      // palautetaan kysytyn setin attribuutit
      return $this->magento_attribute_list[$attribute_set_id];
    }

    // array attribuuteista
    $attr_list = array();
    $this->log('magento_tuotteet', "Haetaan tuotteiden atribuuttiryhm�t");

    try {
      // Haetaan kaikki attribute setit magentosta
      $attribute_filter = [
        'searchCriteria' => ''
      ];

      $attribute_sets = $this->get_client()->catalogAttributeSetRepositoryV1GetList($attribute_filter);

      // Haetaan kaikkien settien atribuutit
      foreach ($attribute_sets->result->items->item as $set) {
        $id   = $set->attributeSetId;
        $name = $set->attributeSetName;
        $id_for_search = [
          'attributeSetId' => $id
        ];
        $list = $this->get_client()->catalogProductAttributeManagementV1GetAttributes($id_for_search);

        $this->log('magento_tuotteet', "Haettiin atribuuttiryhma {$id} {$name} (".count($list->result->item)." atribuuttia)");

        $attr_list[$id] = $list;
      }
    }
    catch (Exception $e) {
      $this->_error_count++;
      $this->log('magento_tuotteet', "Virhe! Attribuuttien haussa", $e);
    }

    $this->magento_attribute_list = $attr_list;

    $this->log('magento_tuotteet', "Haettiin ". count($attr_list) . " atribuuttiryhm��");

    // palautetaan kysytyn setin attribuutit
    return $attr_list[$attribute_set_id];
  }

  // Hakee kaikki kategoriat
  private function getCategories() {
    try {
      if (empty($this->_category_tree)) {
        // Haetaan kaikki defaulttia suuremmat kategoriat (2)
        $this->_category_tree = $this->_proxy->call($this->_session, 'catalog_category.tree');
        //$this->_category_tree = $this->_category_tree['children'][0]; # Skipataan rootti categoria
      }

      return $this->_category_tree;
    }
    catch (Exception $e) {
      $this->_error_count++;
      $this->log('magento_tuotteet', "Virhe! Kategorioiden hakemisessa", $e);
    }
  }

  // Etsii kategoriaa nimelt� Magenton kategoria puusta.
  private function findCategory($name, $root) {
    $category_id = false;

    foreach ($root as $i => $category) {

      // Jos l�ytyy t�st� tasosta nii palautetaan id
      if (strcasecmp($name, $category['name']) == 0) {

        // Jos kyseisen kategorian alla on saman niminen kategoria,
        // palautetaan sen id nykyisen sijasta (osasto ja try voivat olla saman niminis�).
        if (!empty($category['children']) and strcasecmp($category['children'][0]['name'], $name) == 0) {
          return $category['children'][0]['category_id'];
        }

        return $category_id = $category['category_id'];
      }

      // Muuten jatketaan ettimist�
      $r = $this->findCategory($name, $category['children']);

      if ($r != null) {
        return $r;
      }
    }

    // Mit��n ei l�ytyny
    return $category_id;
  }

  // Etsii asiakasryhm�� nimen perusteella Magentosta, palauttaa id:n
  private function findCustomerGroup($name) {
    $name = utf8_encode($name);

    $this->log('magento_tuotteet', "Etsit��n asiakasryhm� nimell� '{$name}'");

    $customer_group_filters = [
      'searchCriteria' => ''
    ];

    $customer_groups = $this->get_client()->customerGroupRepositoryV1GetList($customer_group_filters);

    foreach ($customer_groups->result->items->item as $asryhma) {
      if (strcasecmp($asryhma->code, $name) == 0) {
        $id = $asryhma->id;

        $this->log('magento_tuotteet', "L�ydettiin asiakasryhm� '{$id}'");

        return $id;
      }
    }

    $this->log('magento_tuotteet', "Asiakasryhm�� ei l�ytynyt!");

    return 0;
  }

  // Palauttaa attribuutin option id:n annetulle atribuutille ja arvolle
  private function get_option_id($name, $value, $attribute_set_id) {
    $name = utf8_encode($name);
    $value = utf8_encode($value);
    $attribute_list = $this->getAttributeList($attribute_set_id);
    $attribute_id = '';

    // Etsit��n halutun attribuutin id
    foreach ($attribute_list->result->item as $attribute) {
      if (strcasecmp($attribute->attributeCode, $name) == 0) {
        $attribute_id = $attribute->attributeId;
        $attribute_type = $attribute->frontendInput;
        $attribute_code = $attribute->attributeCode;

        $this->log('magento_tuotteet', "Atribuutti '{$name}' ({$value}) l�ytyi setist� {$attribute_set_id}, attribute_id: {$attribute_id}, attribute_type: {$attribute_type}");
        break;
      }
    }

    // Jos attribuuttia ei l�ytynyt niin turha etti� option valuea
    if (empty($attribute_id)) {
      $this->log('magento_tuotteet', "Atribuuttia '{$name}' ei l�ydetty setist� {$attribute_set_id}");

      return false;
    }

    // Jos dynaaminen parametri on matkalla teksti- tai hintakentt��n niin idt� ei tarvita, palautetaan vaan arvo
    if ($attribute_type == 'text' or $attribute_type == 'textarea' or $attribute_type == 'price') {
      $this->log('magento_tuotteet', "Palautetaan value, kun attribute_type: {$attribute_type}");
      return $value;
    }

    // Haetaan kaikki attribuutin optionssit

    //attributeCode to array for options call
    $attribute_code_array = [
      'attributeCode' => $attribute_code
    ];

    $options = $this->get_client()->catalogProductAttributeOptionManagementV1GetItems($attribute_code_array);

    // Etit��n optionsin value
    foreach ($options->result->item as $option) {
      if (strcasecmp($option->label, $value) == 0) {
        $this->log('magento_tuotteet', "Palautetaan option-value: {$option->value}");
        return $option->value;
      }
    }

    // Jos optionssia ei ole mutta tyyppi on select niin luodaan se
    if ($attribute_type == "select" or $attribute_type == "multiselect") {
      $optionToAdd = [
        'attributeCode' => $attribute_code,
        'option' => [
          'label' => $value,
          'value' => '',
          'isDefault' => 0
        ]
      ];

      $this->get_client()->catalogProductAttributeOptionManagementV1Add($optionToAdd);

      $this->log('magento_tuotteet', "Luotiin uusi attribuutti $value optioid $attribute_id");

      // Haetaan kaikki attribuutin optionssit uudestaan..
      $options = $this->get_client()->catalogProductAttributeOptionManagementV1GetItems($attribute_code_array);

      // Etit��n optionsin value uudestaan..
      foreach ($options->result->item as $option) {
        if (strcasecmp($option->label, $value) == 0) {
          $this->log('magento_tuotteet', "Palautetaan option-value(2285): {$option->value}");
          return $option->value;
        }
      }
    }

    $this->log('magento_tuotteet', "Attribuutin '{$name}' arvon '{$value}' haku setist� {$attribute_set_id} ei onnistunut.");

    // Mit��n ei l�ytyny
    return false;
  }

  // Hakee $status -tilassa olevat tilaukset Magentosta ja merkkaa ne noudetuksi.
  // Palauttaa arrayn tilauksista
  private function hae_tilaukset($status = 'processing') {
    $this->log('magento_tilaukset', "Haetaan tilauksia");

    $orders = array();

    // Toimii ordersilla
    $filter = [
      'searchCriteria' => [
          'filterGroups' => [
              [
                  'filters' => [
                      [
                          'field' => 'status',
                          'value' => $status,
                          'condition_type' => 'eq'
                      ]
                  ]
              ]
          ]
      ]
    ];

    // Uusia voi hakea? state => 'new'
    //$filter = array(array('state' => array('eq' => 'new')));

    // N�in voi hakea yhden tilauksen tiedot
    //return array($this->_proxy->call($this->_session, 'sales_order.info', '100019914'));

    // Haetaan tilaukset (orders.status = 'processing')
    $fetched_orders = $this->get_client()->salesOrderRepositoryV1GetList($filter);

    if ($fetched_orders->result->totalCount == 0) {
      return $orders;
    }

    if (is_array($fetched_orders->result->items->item) == false) {
      $fetched_orders_array[] = $fetched_orders->result->items->item;
    }
    else {
      $fetched_orders_array = $fetched_orders->result->items->item;
    }

    // HUOM: invoicella on state ja orderilla on status
    // Invoicen statet 'pending' => 1, 'paid' => 2, 'canceled' => 3
    // Invoicella on state
    // $filter = array(array('state' => array('eq' => 'paid')));
    // Haetaan laskut (invoices.state = 'paid')

    foreach ($fetched_orders_array as $order) {
      $this->log('magento_tilaukset', "Haetaan tilaus {$order->incrementId}");

      $order_id = [
        'id' => $order->entityId
      ];      

      // Haetaan tilauksen tiedot (orders)
      $temp_order = $this->get_client()->salesOrderRepositoryV1Get($order_id);

      // Looptaan tilauksen statukset
      foreach ($temp_order->result->statusHistories->item as $historia) {
        // Jos tilaus on ollut kerran jo processing_pupesoft, ei haeta sit� en��
        $_status = $historia->status;

        if ($_status == "processing_pupesoft" and $this->_sisaanluvun_esto == "YES") {
          $this->log('magento_tilaukset', "Tilausta on k�sitelty {$_status} tilassa, ohitetaan sis��nluku");
          // Skipataan t�m� $order
          continue 2;
        }
      }

      $orders[] = $temp_order;

      try {
        // P�ivitet��n tilauksen tila ett� se on noudettu pupesoftiin
        
        $_data = [
          'id' => $order->entityId,
          'statusHistory' => [
            'comment' => 'Tilaus noudettu toiminnanohjaukseen.',
            'isCustomerNotified' => 0,
            'isVisibleOnFront' => 0,
            'parentId' => $order->entityId,
            'status' => 'processing_pupesoft'
          ]
        ];

        $this->get_client()->salesOrderManagementV1AddComment($_data);
      }
      catch(Exception $e) {
        $this->log('magento_tilaukset', "Kommentin lis�ys tilaukselle {$order->incrementId} ep�onnistui", $e);
      }
    }

    // Kirjataan kumpaankin logiin
    $_count = count($orders);

    $this->log('magento_tilaukset', "{$_count} tilausta haettu");

    // Palautetaan l�ydetyt tilaukset
    return $orders;
  }

  // Tapahtumaloki
  private function log($log_name, $message, $exception = null) {
    if (!empty($exception)) {
      $message .= "\nfaultcode: " . $exception->faultcode;
      $message .= "\nmessage:   " . $exception->getMessage();
    }

    pupesoft_log($log_name, $message);
  }

  // debug level logging
  private function debug($log_name, $string, $exception = null) {
    if ($this->debug_logging === false) {
      return;
    }

    $string = print_r($string, true);

    $this->log($log_name, $string, $exception);
  }

  // Poistaa tuotteen kaikki kuvat ja lis�� ne takaisin
  private function lisaa_ja_poista_tuotekuvat($product_id, $pupesoft_tuote_id, $toiminto, $tuote_sku) {
    if (empty($product_id) or empty($pupesoft_tuote_id) or empty($toiminto)) {
      return;
    }

    // Jos ei haluta k�sitell� tuotekuvia, palautetaan tyhj� array
    if ($this->magento_lisaa_tuotekuvat === false) {
      $this->log('magento_tuotteet', 'Tuotekuvia ei k�sitell�.');

      return;
    }

    if ($toiminto == 'update' and $this->magento_lisaa_tuotekuvat === 'create_only') {
      $this->log('magento_tuotteet', "Tuotekuvia ei k�sitell� p�ivityksen yhteydess�");

      return;
    }

    $types = array('image', 'small_image', 'thumbnail');

    // Pit�� ensin poistaa kaikki tuotteen kuvat Magentosta
    $magento_pictures = $this->listaa_tuotekuvat($tuote_sku);

    // Poistetaan kuvat
    foreach ($magento_pictures as $file_id) {
      $this->poista_tuotekuva($tuote_sku, $file_id);
    }

    // Haetaan tuotekuvat Pupesoftista
    $tuotekuvat = $this->hae_tuotekuvat($pupesoft_tuote_id);

    // Loopataan tuotteen kaikki kuvat
    foreach ($tuotekuvat as $kuva) {

      // Lis�t��n tuotekuva kerrallaan
      try {
        $data = [
          'sku' => $tuote_sku,
          'entry' => [
            'mediaType' => 'image',
            'label' => '',
            'position' => 0,
            'disabled' => false,
            'types' => $types,
          ],
          'content' => [
            'base64EncodedData' => $kuva['content'],
            'type' => $kuva['mime'],
            'name' => $kuva['name']
          ]
        ];

        $return = $this->get_client()->catalogProductAttributeMediaGalleryManagementV1Create($data);

        $this->log('magento_tuotteet', "Lis�tty kuva '{$kuva['name']}'");
        $this->debug('magento_tuotteet', $return);
      }
      catch (Exception $e) {
        // Nollataan base-encoodattu kuva, ett� logi ei tuu isoks
        $data[1]["file"]["content"] = '...content poistettu logista...';

        $this->log('magento_tuotteet', "Virhe! Kuvan lis�ys ep�onnistui", $e);
        $this->debug('magento_tuotteet', $data);
        $this->_error_count++;
      }
    }
  }

  // Hakee tuotteen tuotekuvat Magentosta
  private function listaa_tuotekuvat($tuote_sku) {
    $pictures = array();
    $return = array();

    $sku_GetList = [
      'sku' => $tuote_sku
    ];

    // Haetaan tuotteen kuvat
    try {
      $pictures = $this->get_client()->catalogProductAttributeMediaGalleryManagementV1GetList($sku_GetList);
    }
    catch (Exception $e) {
      $this->log('magento_tuotteet', "Virhe! Kuvalistauksen ep�onnistui", $e);
      $this->_error_count++;
    }
    //haetaan kuvan id, magento 2 soap käyttään kuvan id:tä kuvien poistoa varten
    $pictures_item = $pictures->result->item;
    
    //if only one picture to return, convert from object to array
    if(is_array($pictures_item) == false) {
      $return[] = $pictures_item->id;
      return $return;
    }

    foreach ($pictures_item as $picture) {
      $return[] = $picture->id;
    }

    return $return;
  }

  // Poistaa tuotteen tuotekuvan Magentosta
  private function poista_tuotekuva($tuote_sku, $entryId) {
    $return = false;

    $product_info_sku = [
      'sku' => $tuote_sku
    ];

    $product_info = $this->get_client()->catalogProductAttributeMediaGalleryManagementV1GetList($product_info_sku);
    $filename = $product_info->result->item->file;

    $remove_details = [
      'sku' => $tuote_sku,
      'entryId' => $entryId
    ];

    // Poistetaan tuotteen kuva
    try {
      $return = $this->get_client()->catalogProductAttributeMediaGalleryManagementV1Remove($remove_details);

      $this->log('magento_tuotteet', "Poistetaan '{$filename}'");
    }
    catch (Exception $e) {
      $this->log('magento_tuotteet', "Virhe! Kuvan poisto ep�onnistui '{$filename}'", $e);
      $this->_error_count++;

      return false;
    }

    return $return;
  }

  // Hakee tuotteen tuotekuvat Pupesoftista
  private function hae_tuotekuvat($tunnus) {
    global $kukarow;

    // Populoidaan tuotekuvat array
    $tuotekuvat = array();

    try {
      $query = "SELECT
                liitetiedostot.data,
                liitetiedostot.filetype,
                liitetiedostot.filename
                FROM liitetiedostot
                WHERE liitetiedostot.yhtio         = '{$kukarow['yhtio']}'
                AND liitetiedostot.liitostunnus    = '{$tunnus}'
                AND liitetiedostot.liitos          = 'tuote'
                AND liitetiedostot.kayttotarkoitus = 'TK'
                ORDER BY liitetiedostot.jarjestys DESC,
                liitetiedostot.tunnus DESC";
      $result = pupe_query($query);

      while ($liite = mysql_fetch_assoc($result)) {
        $file = array(
          'content' => base64_encode($liite['data']),
          'mime'    => $liite['filetype'],
          'name'    => $liite['filename']
        );

        $tuotekuvat[] = $file;
      }
    }
    catch (Exception $e) {
      $this->_error_count++;
      $this->log('magento_tuotteet', "Virhe! Tietokantayhteys on poikki. Yritet��n uudelleen.", $e);
    }

    // Palautetaan tuotekuvat
    return $tuotekuvat;
  }

  // Hakee tuotteen kieliversiot(tuotenimitys, tuotekuvaus) Pupesoftista
  private function hae_kieliversiot($tuotenumero) {
    global $kukarow;

    $kieliversiot_data = array();

    try {
      $query = "SELECT
                kieli, laji, selite
                FROM
                tuotteen_avainsanat
                WHERE yhtio = '{$kukarow['yhtio']}'
                AND tuoteno = '{$tuotenumero}'
                AND laji    IN ('nimitys','kuvaus', 'yksikko', 'mainosteksti')";
      $result = pupe_query($query);

      while ($avainsana = mysql_fetch_assoc($result)) {
        $kieli  = $avainsana['kieli'];
        $laji   = utf8_encode($avainsana['laji']);
        $selite = utf8_encode($avainsana['selite']);

        // J�sennell��n tuotteen avainsanat kieliversioittain
        $kieliversiot_data[$kieli][$laji] = $selite;
      }
    }
    catch (Exception $e) {
      $this->_error_count++;
      $this->log('magento_tuotteet', "Virhe! Tietokantayhteys on poikki. Yritet��n uudelleen.", $e);
    }

    // Palautetaan kieliversiot
    return $kieliversiot_data;
  }

  private function kauppakohtaiset_hinnat(Array $tuote) {
    $kauppakohtaiset_hinnat = array();
    $kauppakohtaiset_verokannat = array();
    $return_array = array();

    // Kauppakohtaiset hinnat tulee erikoisparametreist�
    foreach ($this->_verkkokauppatuotteet_erikoisparametrit as $erikoisparametri) {
      $key = $erikoisparametri['nimi'];

      if ($key == 'kauppakohtaiset_hinnat') {
        $kauppakohtaiset_hinnat = $erikoisparametri['arvo'];
        continue;
      }

      if ($key == 'kauppakohtaiset_verokannat') {
        $kauppakohtaiset_verokannat = $erikoisparametri['arvo'];
        continue;
      }
    }

    // Esimerkiksi:
    //
    // $kauppakohtaiset_hinnat = array(
    //   'myyntihinta'   => array('7','8'),
    //   'hinnastohinta' => array('7','8')
    // );
    //
    // $kauppakohtaiset_verokannat = array(
    //   # magento_store_id => magento_tax_class_id
    //   '7' => 6,
    //   '8' => 6
    // );

    // Valitaan tuotteen kauppan�kym�kohtainen hinta
    foreach ($kauppakohtaiset_hinnat as $tuotekentta => $kauppatunnukset) {
      foreach ($kauppatunnukset as $kauppatunnus) {
        // Jos asetettu hintakentt� on 0 tai '' niin skipataan, t�m�
        // sit�varten ett� voidaan antaa "default"-arvoja(myyntihinta) jotka yliajetaan esimerkiksi
        // hinnastohinnalla, mutta vain jos sellainen l�ytyy ja on voimassa
        if (empty($tuote[$tuotekentta])) {
          continue;
        }

        $tuotteen_kauppakohtainen_data = array(
          'price' => $tuote[$tuotekentta]
        );

        if (!empty($kauppakohtaiset_verokannat[$kauppatunnus])) {
          $tuotteen_kauppakohtainen_data['tax_class_id'] = $kauppakohtaiset_verokannat[$kauppatunnus];
        }

        // Key on store id, arvo on Magentoon passattava data
        $return_array[$kauppatunnus] = $tuotteen_kauppakohtainen_data;
      }
    }

    // Lokitetaan tieto
    foreach ($return_array as $log_key => $log_value) {
      $log_message = "Poikkeava hinta {$log_value['price']} kauppaan {$log_key}";

      if (!empty($log_value['tax_class_id'])) {
        $log_message .= ", poikkeava veroluokka {$log_value['tax_class_id']}";
      }

      $this->log('magento_tuotteet', $log_message);
      $this->debug('magento_tuotteet', $return_array);
    }

    // Esimerkiksi:
    // $return_array = array(
    //   7 => array('price' => 10.0, 'tax_class_id' => 6),
    //   8 => array('price' => 17.50),
    // );
    return $return_array;
  }
}
