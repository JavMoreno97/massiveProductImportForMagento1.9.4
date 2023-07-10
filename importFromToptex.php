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
          $imagesURL = array();
          foreach ($productColor['packshots'] as $image)
            $imagesURL[] = $image['url'];

          $colorImagesPath = downloadImageFromURL($curl, $imagesURL);

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
            
            $product->setCategoryIds($categoryIds);  
            try {
              $product->save();
              updateProductStock($product->getId());

              if(!$combinationCount++)
                $basePrice = $productSize['publicUnitPrice'];

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

              $product->setMediaGallery(array('images' => array(), 'values' => array()))->save();
              // $k = 0;
              // foreach($baseImagesPath as $img){
              //   // if($k++ == 0)
              //     $product->addImageToMediaGallery($img, array('image', 'small_image', 'thumbnail'), false, false);
              //   // else
              //     // $product->addImageToMediaGallery($img, null, false, false);
              //   // $product->save();
              // }

              // foreach($colorImagesPath as $img){
              //   // if($k++ == 0)
              //     $product->addImageToMediaGallery($img, array('image', 'small_image', 'thumbnail'), false, false);
              //   // else
              //     // $product->addImageToMediaGallery($img, null, false, false);
              //   // $product->save();
              // }

              // Regenerate the product's thumbnails and resized images
              // Mage::getModel('catalog/product_image')->clearCache();
              // Mage::getModel('catalog/product_image')->init($product, 'image')->resize();
              // Mage::getModel('catalog/product_image')->init($product, 'small_image')->resize();
              // Mage::getModel('catalog/product_image')->init($product, 'thumbnail')->resize();
              
              /* ------------------------ END OF PRODUCT IMAGE LINKING CODE ------------------------ */

              
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
            ->setWebsiteIds(array(1)) // Replace with the appropriate website ID
            ->setVisibility(Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH) // Change visibility as needed
            ->setStatus(Mage_Catalog_Model_Product_Status::STATUS_ENABLED) // Change status as needed
            ->setPrice($basePrice) // Set the initial price
            ->setTaxClassId(2); //tax class (0 - none, 1 - default, 2 - taxable, 4 - shipping)
            
          $configurableProduct->setCategoryIds($categoryIds);  
          $configurableProduct->getTypeInstance()->setUsedProductAttributeIds([$colorAttribute->getId(), $sizeAttribute->getId()]);
          $configurableAttributesData = $configurableProduct->getTypeInstance()->getConfigurableAttributesAsArray();
          
          $configurableProduct->setCanSaveConfigurableAttributes(true);
          $configurableProductTest = Mage::getModel('catalog/product')->loadByAttribute('sku', $productData['catalogReference']);
          if (!$configurableProductTest || $configurableProductTest->getTypeId() != 'configurable')
            $configurableProduct->setConfigurableAttributesData($configurableAttributesData);

          $configurableProduct->setConfigurableProductsData($configurableProductsData);
          
          $configurableProduct->save();

          updateProductStock($configurableProduct->getId(), true);

          $configurableProduct->setMediaGallery(array('images' => array(), 'values' => array()))->save();
          foreach($baseImagesPath as $img){
            // if($k++ == 0)
              $configurableProduct->addImageToMediaGallery($img, array('image', 'small_image', 'thumbnail'), false, false);
            // else
              // $product->addImageToMediaGallery($img, null, false, false);
            // $product->save();
          }

          // Clear cache
          Mage::app()->getCacheInstance()->cleanType('block_html');
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