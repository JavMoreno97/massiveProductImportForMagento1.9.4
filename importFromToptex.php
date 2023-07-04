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
      'page_number' => 1,
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
      $products = json_decode($response, true);
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

        /* ------------------------ IMAGE DOWNLOAD CODE ------------------------ */

        // // Download the file using cURL
        // $tmpImgPath = array();
        // curl_setopt_array($curl, array(
        //   CURLOPT_RETURNTRANSFER => 1,
        //   CURLOPT_FOLLOWLOCATION => 1,
        //   CURLOPT_SSL_VERIFYPEER => 0,
        //   CURLOPT_SSL_VERIFYHOST => 0,
        //   CURLOPT_TIMEOUT => 120
        // ));
            
        // // Upload images
        // foreach ($productData['images'] as $image) {
        //   $fileTransferUrl = $image['url'];

        //   // Download the file using cURL
        //   curl_setopt($curl, CURLOPT_URL, $fileTransferUrl);
        //   curl_setopt($curl, CURLOPT_HEADER, 1);
        //   curl_setopt($curl, CURLOPT_NOBODY, 1);
        //   $header = curl_exec($curl);

        //   // Check for errors
        //   if ($header === false) {
        //     echo 'Error getting the image: ' . curl_error($curl);
        //   } 
        //   else {
        //     $filename = '';
        //     if (preg_match('/filename="(.*?)"/', $header, $matches)) {
        //         $filename = $matches[1];
        //     }

        //     // Download the file and save it with the extracted filename
        //     curl_setopt($curl, CURLOPT_HEADER, 0);
        //     curl_setopt($curl, CURLOPT_NOBODY, 0);
        //     $fileData = curl_exec($curl);

        //     if ($fileData !== false) {
        //       $tmpImgPath[] = Mage::getBaseDir('media') . DS . 'import' . DS . $filename;
        //       file_put_contents(end($tmpImgPath), $fileData);
        //     } else {
        //         echo "Failed to download the image using the File Transfer URL: " . $fileTransferUrl . "\n";
        //     }
        //   }
        // }

        /* ------------------------ END OF IMAGE DOWNLOAD CODE ------------------------ */
        
        // Import or get product attributes
        $combinationCount = 0;
        $simpleProductsIds = array();

        foreach($productData['colors'] as $productColor){
          $colorAttributeOptionID = addAttributeOption($colorAttribute, $productColor['colors']['es']);
          foreach($productColor['sizes'] as $productSize){
            $sizeAttributeOptionID = addAttributeOption($sizeAttribute, $productSize['size']);

            // Define the simple product data
            $productDataMagento = array(
              'sku' => $productSize['sku'],
              'name' => $productData['designation']['es'] . ' ' .  $combinationCount,
              'description' => $productData['description']['es'],
              'short_description' => $productData['description']['es'],
              'attribute_set_id' => $attributeSetID,
              'type_id' => 'simple',
              'price' => 100.00,
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
            
            $product->setCategoryIds($categoryIds);  
            try {
              $product->save();

              // Get the stock item associated with the product
              $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product->getId());
              
              // Create a new stock item if it doesn't exist
              if (!$stockItem->getId()) {
                $stockItem->setData('product_id', $product->getId());
                $stockItem->setData('stock_id', 1); // Replace with the appropriate stock ID if necessary
              }

              // Set manage stock and is_in_stock values
              $stockItem->setData('manage_stock', 1); // 1 = Active, 0 = Inactive
              $stockItem->setData('is_in_stock', 1); // 1 = In stock, 0 = Out of stock

              // Save the stock item
              $stockItem->save();
              
              $connection = Mage::getSingleton('core/resource')->getConnection('core_write');
              $connection->update('mg_cataloginventory_stock_item', [
                'qty' => 1
              ], "item_id = " . $stockItem->getId());

              /* ------------------------ PRODUCT IMAGE LINKING CODE ------------------------ */

              // Get the product's media gallery
              // $mediaGallery = $product->getMediaGallery('images');

              // if (is_array($mediaGallery) && count($mediaGallery) > 0) {
              //   foreach ($mediaGallery as $mediaGalleryEntry) {
              //     $file = $mediaGalleryEntry->getFile();
              //     $subDirectory = substr($file, 0, 2); // Extract the first two characters
          
              //     $filePath = Mage::getBaseDir('media') . DS . 'catalog' . DS . 'product' . DS . $subDirectory . DS . $file;
          
              //     // Unlink the image from the product
              //     $product->removeImage($file);
          
              //     // Delete the image file from the directory
              //     if (file_exists($filePath)) {
              //         unlink($filePath);
              //     }
              //   }
              // }

              // $product->setMediaGallery(array('images' => array(), 'values' => array()))->save();
              // $k = 0;
              // foreach($tmpImgPath as $img){
              //   if($k++ == 0)
              //     $product->addImageToMediaGallery($img, array('image', 'small_image', 'thumbnail'), false, false);
              //   else
              //     $product->addImageToMediaGallery($img, null, false, false);
              //   $product->save();
              // }

              // Regenerate the product's thumbnails and resized images
              // Mage::getModel('catalog/product_image')->clearCache();
              // Mage::getModel('catalog/product_image')->init($product, 'image')->resize();
              // Mage::getModel('catalog/product_image')->init($product, 'small_image')->resize();
              // Mage::getModel('catalog/product_image')->init($product, 'thumbnail')->resize();
              
              /* ------------------------ END OF PRODUCT IMAGE LINKING CODE ------------------------ */

              $simpleProductsIds[] = $product->getId();
            } catch (Exception $e) {
              echo "Error: " . $e->getMessage() . "\n";
            }
          }
        }

        // if(!empty($simpleProductsIds)){
        //   $configurableProduct = Mage::getModel('catalog/product');
        //   $configurableProduct
        //     ->setSku('configurable-product')
        //     ->setAttributeSetId(4) // Replace with the appropriate attribute set ID
        //     ->setTypeId('configurable')
        //     ->setWebsiteIds(array(1)) // Replace with the appropriate website ID
        //     ->setVisibility(Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH) // Change visibility as needed
        //     ->setStatus(Mage_Catalog_Model_Product_Status::STATUS_ENABLED) // Change status as needed
        //     ->setName('Configurable Product')
        //     ->setPrice(0) // Set the initial price
        //     ->setDescription('This is a configurable product')
        //     ->setShortDescription('Configurable Product')
        //     ->setStockData(array(
        //         'manage_stock' => 1,
        //         'is_in_stock' => 1,
        //     ))
        //     ->save();
        // }
      }
      echo "Product import complete.";
    }
  }
  curl_close($curl);
?>