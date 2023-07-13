<?php
  // Magento initialization
  require_once '../app/Mage.php';
  include 'src/credentials.php';
  include 'src/functions.php';
  Mage::app();

  // Constants
  $default_lang = 'es';

  // API request body
  $data = array(
    'username' => API_USERNAME,
    'password' => API_PASSWORD
  );

  // Create the headers array
  $headers = array(
    'Content-Type: application/json',
    'x-api-key: ' . API_KEY
  );

  $count = 1;
	$flag = false;

	if(!empty($_GET['count']))
		$count = $_GET['count'];

	$productCount = 0;

  // Initialize cURL
  $curl = curl_init();

  // Set cURL options
  curl_setopt_array($curl, array(
      CURLOPT_URL => API_URL . '/v3/authenticate',
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => json_encode($data)
  ));

  // Execute the cURL request
  $response = curl_exec($curl);

  // Check for errors
  if ($response === false) {
      echo 'Error connecting to the API: ' . curl_error($curl);
  } 
  else {
    // Add token to header
    $authResponse = json_decode($response, true);
    $headers[] = 'x-toptex-authorization: ' . $authResponse['token'];
    
    // Set new data
    $data = array(
      'usage_right' => 'b2c_uniquement',
      'lang' => 'es',
      // 'family' => 'Accesorios',
      'subfamily' => 'Masques',
      'page_size' => 1,
      'page_number' => $count,
    );

    curl_setopt_array($curl, array(
      CURLOPT_URL => API_URL . '/v3/products/all?' . http_build_query($data),
      CURLOPT_HTTPGET => true,
      CURLOPT_HTTPHEADER => $headers
    ));

    // Execute the cURL request
    $response = curl_exec($curl);

    // Check for errors
    if ($response === false) {
      echo 'Error connecting to the API: ' . curl_error($curl);
    } 
    else {
      $products = json_decode($response, true);
      if(empty($products)){
        echo 'All products were successfully imported';
        
        // Clear cache
        Mage::app()->getCacheInstance()->cleanType('block_html');
        return true;
      }
      
      // Create / Retrieve the AttributeSet for Product Attributes
      if(!$attributeSetID = addAttributeSet("TopTex Attributes"))
        die("Couldn't find attribute set.");

      if(!$attributeGroupID = addAttributeGroup($attributeSetID, "TopTex Extra"))
        die("Couldn't find attribute group.");
        
      $colorAttribute = addAttribute($attributeSetID, $attributeGroupID, "Color");
      if(!$colorAttribute->getId())
        die("Couldn't find attribute 'Color'.");

      $sizeAttribute = addAttribute($attributeSetID, $attributeGroupID, "Talla");
      if(!$sizeAttribute->getId())
        die("Couldn't find attribute 'Talla'.");
      
      $parentCatID = addCategory("TopTex");
      foreach ($products['items'] as $productData) {
        // Import or get parent and children category
        $categoryIds = array();
        array_push($categoryIds, $parentCatID);
        array_push($categoryIds, addCategory($productData['family'][$default_lang], end($categoryIds)));
        array_push($categoryIds, addCategory($productData['sub_family'][$default_lang], end($categoryIds)));

        if(empty($categoryIds = array_filter($categoryIds))){
          echo "Warning: Product '" . $productData['designation']['es'] . "' couldn't be imported: No category provided / Invalid categories." . PHP_EOL;
          continue;
        }

        // Download the product base images (Global for every combinaction)
        $imagesURL = array();
        foreach ($productData['images'] as $image)
          $imagesURL[] = $image['url'];

        $baseImagesPath = downloadImageFromURL($curl, $imagesURL);

        // Import or get product attributes
        $combinationCount = 0;
        $basePrice = 0;
        $configurableProductsData = array();

        foreach($productData['colors'] as $productColor){
          $colorAttributeOptionID = addAttributeOption($colorAttribute, $productColor['colors']['es']);
  
          // Download the product specific images (Based on the "Color" attribute)
          // $imagesURL = array();
          // foreach ($productColor['packshots'] as $image)
          //   $imagesURL[] = $image['url'];

          // $colorImagesPath = downloadImageFromURL($curl, $imagesURL);

          foreach($productColor['sizes'] as $productSize){
            $sizeAttributeOptionID = addAttributeOption($sizeAttribute, $productSize['size']);

            $productName = $productData['designation']['es'];
            $productName .= ' - ' . $colorAttribute->getFrontendLabel() . ' ' . $productColor['colors']['es'];
            $productName .= ' - ' . $sizeAttribute->getFrontendLabel() . ' ' . $productSize['size'];

            // Define the simple product data
            $productDataMagento = array(
              'sku' => $productSize['sku'],
              'name' => $productName,
              'description' => $productData['description']['es'],
              'short_description' => $productData['description']['es'],
              'attribute_set_id' => $attributeSetID,
              'type_id' => 'simple',
              'price' => $productSize['publicUnitPrice'],
              'weight' => 0, // Set the weight of the product
              'tax_class_id' => 2, // Set the tax class ID, 2 represents the default tax class
              'status' => 1,
              'visibility' => 1, // 1 = Not Visible Individually
            );

            // Create the configurable product
            $product = Mage::getModel('catalog/product');
            $product->setData($productDataMagento);
            
            $product->setData($sizeAttribute->getAttributeCode(), (int)$sizeAttributeOptionID);
            $product->setData($colorAttribute->getAttributeCode(), (int)$colorAttributeOptionID);
            $product->setWebsiteIds(array(Mage::app()->getStore(true)->getWebsite()->getId()));

            $product->setNewsFromDate(null);
            $product->setNewsToDate(null);
            $product->setSpecialPrice(null);
            $product->setSpecialFromDate(null);
            $product->setSpecialToDate(null);
            $product->setCustomDesignFrom(null);
            $product->setCustomDesignTo(null);
            $product->setMsrp(null); // Set MSRP to null
            $product->setData('is_recurring', 0); // Set "is_recurring" attribute to 0
            $product->setMetaTitle(null); // Set meta title to NULL
            $product->setMetaDescription(null); // Set meta title to NULL
            $product->setCustomLayoutUpdate(null); // Set custom layout update to NULL
            $product->setCustomDesign(null); // Set custom design to NULL
            $product->setPageLayout(null); // Set page layout to NULL
            $product->setGiftMessageAvailable($giftMessageAvailable); // Set the gift message availability
            $product->setCountryOfManufacture($countryOfManufacture); // Set the country of manufacture
            $product->setMsrpEnabled(2); // Enable MSRP
            $product->setMsrpDisplayActualPriceType(4); // Display actual price

    
            $product->setCategoryIds($categoryIds);  
            try {
              $product->save();
              updateProductStock($product->getId());

              if(!$combinationCount++)
                $basePrice = $productSize['publicUnitPrice'];

              /* ------------------------ SIMPLE PRODUCT IMAGE LINKING CODE ------------------------ */
              // $attributes = $product->getTypeInstance()->getSetAttributes();
              // if (isset ( $attributes ['media_gallery'] )) {
              //   $gallery = $attributes ['media_gallery'];
              //   //Get the images
              //   $galleryData = $product->getMediaGallery ();
              //   foreach ( $galleryData ['images'] as $image ) {
              //     //If image exists
              //     if ($gallery->getBackend()->getImage ( $product, $image ['file'] )) {
              //       $gallery->getBackend()->removeImage ( $product, $image ['file'] );
              //     }
              //   }
              //   $product->save ();
              // }

              // foreach ( $baseImagesPath as $img )
              // {
              //     try {
              //       $product->setMediaGallery ( array ('images' => array (), 'values' => array () ) );
              //       $product->addImageToMediaGallery ( $img, array ("thumbnail", "small_image", "image" ), false, false )->save();
              //     } catch ( Exception $e ) {
              //       echo $e->getMessage ();
              //     }
              // }

              // foreach ( $colorImagesPath as $img )
              // {
              //     try {
              //       $product->setMediaGallery ( array ('images' => array (), 'values' => array () ) );
              //       $product->addImageToMediaGallery ( $img, array ("thumbnail", "small_image", "image" ), false, false )->save();
              //     } catch ( Exception $e ) {
              //       echo $e->getMessage ();
              //     }
              // }
              
              /* ------------------------ END OF SIMPLE PRODUCT IMAGE LINKING CODE ------------------------ */

              
              $configurableProductsData[$product->getId()][0] = array(
                'label' => $productSize['size'],
                'attribute_id' => $sizeAttribute->getId(),
                // 'value_index' => '24',
                'is_percent' => '0', 
                'pricing_value' => ''
              );
              $configurableProductsData[$product->getId()][1] = array(
                'label' => $productColor['colors']['es'],           //attribute label
                'attribute_id' => $colorAttribute->getId(),         //attribute ID of attribute
                // 'value_index' => '24',                           //value of 'Green' index of the attribute 'color'
                'is_percent' => '0',                                //fixed/percent price for this option
                'pricing_value' => ''                               //value for the pricing
              );

            } catch (Exception $e) {
              echo "Error: " . $e->getMessage() . "\n";
            }
          }
        }

        if(!empty($configurableProductsData)){
          $configurableProduct = Mage::getModel('catalog/product');
          $configurableProduct
            ->setSku($productData['catalogReference'])
            ->setName($productData['designation']['es']) //product name
            ->setDescription($productData['description']['es'])
            ->setShortDescription($productData['description']['es'])
            ->setAttributeSetId($attributeSetID) // Replace with the appropriate attribute set ID
            ->setTypeId('configurable')
            ->setWebsiteIds(array(Mage::app()->getStore(true)->getWebsite()->getId()))
            ->setVisibility(Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH) // Change visibility as needed
            ->setStatus(Mage_Catalog_Model_Product_Status::STATUS_ENABLED) // Change status as needed
            ->setPrice($basePrice) // Set the initial price
            ->setTaxClassId(2) //tax class (0 - none, 1 - default, 2 - taxable, 4 - shipping)
            ->setNewsFromDate(null)
            ->setNewsToDate(null)
            ->setSpecialPrice(null)
            ->setSpecialFromDate(null)
            ->setSpecialToDate(null)
            ->setCustomDesign(null) // Set custom design to NULL
            ->setCustomDesignFrom(null)
            ->setCustomDesignTo(null)
            ->setMsrp(null) // Set MSRP to null
            ->setData('is_recurring', 0) // Set "is_recurring" attribute to 0;
            ->setCustomLayoutUpdate(null) // Set custom layout update to NULL
            ->setPageLayout(null) // Set page layout to NULL
            ->setMetaDescription(null)
            ->setMetaTitle(null) // Set)meta title to NULL
            ->setMsrpEnabled(2) // Enable MSRP
            ->setMsrpDisplayActualPriceType(4); // Display actual price

            
          $configurableProduct->setCategoryIds($categoryIds);  

          /* ------------------------ CONFIGURABLE PRODUCT IMAGE LINKING CODE ------------------------ */
          $attributes = $configurableProduct->getTypeInstance()->getSetAttributes();
          if (isset ( $attributes ['media_gallery'] )) {
            $gallery = $attributes ['media_gallery'];
            //Get the images
            $galleryData = $configurableProduct->getMediaGallery ();
            foreach ( $galleryData ['images'] as $image ) {
              //If image exists
              if ($gallery->getBackend()->getImage ( $configurableProduct, $image ['file'] )) {
                $gallery->getBackend()->removeImage ( $configurableProduct, $image ['file'] );
              }
            }
          }

          if(empty($baseImagesPath))
            $configurableProduct->save();

          foreach ( $baseImagesPath as $img )
          {
              try {
                $configurableProduct->save();
                $configurableProduct->setMediaGallery ( array ('images' => array (), 'values' => array () ) );
                $configurableProduct->addImageToMediaGallery ( $img, array ("thumbnail", "small_image", "image" ), false, false );
              } catch ( Exception $e ) {
                echo $e->getMessage ();
              }
          }
          /* ------------------------ END OF CONFIGURABLE PRODUCT IMAGE LINKING CODE ------------------------ */

          $configurableProduct->getTypeInstance()->setUsedProductAttributeIds([$colorAttribute->getId(), $sizeAttribute->getId()]);
          $configurableAttributesData = $configurableProduct->getTypeInstance()->getConfigurableAttributesAsArray();
          
          $configurableProduct->setCanSaveConfigurableAttributes(true);
          $configurableProductTest = Mage::getModel('catalog/product')->loadByAttribute('sku', $productData['catalogReference']);
          // if (!$configurableProductTest || $configurableProductTest->getTypeId() != 'configurable')
            $configurableProduct->setConfigurableAttributesData($configurableAttributesData);

          $configurableProduct->setConfigurableProductsData($configurableProductsData);
    
          $configurableProduct->save();

          updateProductStock($configurableProduct->getId(), true);
        }
      }
      $productCount++;
    }
  }

  if($productCount < 1)
    $flag = true; 
    
  curl_close($curl);
?>

<!DOCTYPE html>
<html>
  <head>
    <script src="src/jquery-3.6.0.slim.min.js"></script>
  </head>    
  <body>
    <script>
      $( document ).ready(function() {
        var url = window.location.href;
        var count = '<?php echo $count; ?>';
        var flag = '<?php echo $flag; ?>';
        count =  parseInt(count) + 1
        if(count)
          url = url.split('?');
        if(!flag)
          window.location.replace(url[0]+"?count="+count);
      });
    </script>
  </body>
</html>