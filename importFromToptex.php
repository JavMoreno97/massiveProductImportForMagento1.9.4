<?php
  // Magento initialization
  require_once '../app/Mage.php';
  include 'src/credentials.php';
  include 'src/functions.php';
  Mage::app();

  // Constants
  $default_lang = 'es';

  // Retrieve product data from external API
  $apiUrl = API_URL;
  $apiKey = API_KEY;

  // API request body
  $data = array(
    'username' => API_USERNAME,
    'password' => API_PASSWORD
  );

  // Create the headers array
  $headers = array(
    'Content-Type: application/json',
    'x-api-key: ' . $apiKey
  );

  // Initialize cURL
  $curl = curl_init();

  // Set cURL options
  curl_setopt_array($curl, array(
      CURLOPT_URL => $apiUrl . '/v3/authenticate',
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
  } else {
    // Add token to header
    $authResponse = json_decode($response, true);
    $headers[] = 'x-toptex-authorization: ' . $authResponse['token'];
    
    // Set new data
    $data = array(
      'usage_right' => 'b2c_uniquement',
      'lang' => 'es',
      // 'family' => 'Accesorios',
      'subfamily' => 'Masques',
    );

    curl_setopt_array($curl, array(
      CURLOPT_URL => $apiUrl . '/v3/products/all?' . http_build_query($data),
      CURLOPT_HTTPGET => true,
      CURLOPT_HTTPHEADER => $headers
    ));

    // Execute the cURL request
    $response = curl_exec($curl);

    // Check for errors
    if ($response === false) {
      echo 'Error connecting to the API: ' . curl_error($curl);
    } else {
      // Create / Retrieve the AttributeSet for Product Attributes
      $attributeSetID = createOrRetrieveAttributeSet("TopTex Attributes");

      $products = json_decode($response, true);
      foreach ($products['items'] as $productData) {
        $categoryIds = array();
        // Import or get parent category
        array_push($categoryIds, createOrRetrieveCategory($productData['family'][$default_lang]));

        // Import or get children category
        array_push($categoryIds, createOrRetrieveCategory($productData['sub_family'][$default_lang], $categoryIds[0]));
        
        if(count($productData['colors']) > 1){
          foreach($productData['colors'] as $productColor){
            $color = createOrRetrieveAttribute($attributeSetID, $productColor['colors']['es'], "Color");
            foreach($productColor['sizes'] as $productSize){
              $size = createOrRetrieveAttribute($attributeSetID, $productSize['size'], "Size");
            }
          }
          exit;
        }

        // Create products from the retrieved data
        // $product = Mage::getModel('catalog/product');
        // $product->setSku($productData['sku']); // SKU of the product
        // $product->setName($productData['name']); // Name of the product
        // $product->setDescription($productData['description']); // Description of the product
        // $product->setShortDescription($productData['short_description']); // Short description of the product
        // $product->setPrice($productData['price']); // Price of the product
        // $product->setTypeId('simple'); // Product type (simple, configurable, etc.)
        // $product->setAttributeSetId(4); // Attribute set ID (default attribute set ID is 4)

        // // Set product category
        // $categoryIds = array($productData['category_id']); // Array of category IDs to which the product will belong
        // $product->setCategoryIds($categoryIds);

        // // Set product stock data
        // $product->setStockData(array(
        //     'is_in_stock' => $productData['is_in_stock'], // 1 for in stock, 0 for out of stock
        //     'qty' => $productData['quantity'] // Quantity of the product
        // ));

        // // Set product visibility
        // $product->setVisibility(Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH); // Visibility options: VISIBILITY_NOT_VISIBLE, VISIBILITY_IN_CATALOG, VISIBILITY_IN_SEARCH, VISIBILITY_BOTH

        // // Set product status
        // $product->setStatus(1); // 1 for enabled, 2 for disabled

        // // Save the product
        // try {
        //     $product->save();
        //     echo "Product with SKU {$product->getSku()} has been created successfully.<br>";
        // } catch (Exception $e) {
        //     echo "Error occurred: " . $e->getMessage() . "<br>";
        // }
      }

      echo "Product import complete.";
    }
  }
  curl_close($curl);
?>