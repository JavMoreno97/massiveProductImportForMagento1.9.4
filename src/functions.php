<?php
  /**
     * Generate a valid, parsed URL from a string.
     *
     * @param string $name
     *
     * @return string Parsed URL
  */
  function generateUrlKey($name) {
    $urlKey = strtolower($name);
    $urlKey = preg_replace('/[^a-z0-9]+/', '-', $urlKey);
    $urlKey = trim($urlKey, '-');
    return $urlKey;
  }

  /**
     * Adds current Category as a new Object to the database.
     *
     * @param string $categoryName Category name
     * @param int $parentId Parent category ID
     *
     * @return int Category ID
  */
  function addCategory ($categoryName, $parentId = 1){
    if(empty($categoryName))
      return null;

    if (!$id_category = getCategory($categoryName)) {
      // Create a new category
      $category = Mage::getModel('catalog/category');
      $category->setName($categoryName);
      $category->setUrlKey(generateUrlKey($categoryName));
      $category->setIsActive(1);
      $category->setDisplayMode('PRODUCTS');
      $category->setIsAnchor(1);
      $category->setStoreId(Mage_Core_Model_App::ADMIN_STORE_ID);

      // Load the parent category
      $parentCategory = Mage::getModel('catalog/category')->load((int) $parentId);

      // Assign the parent category to the new category
      $category->setPath($parentCategory->getPath());

      // Save the category
      try {
        $category->save();
        return $category->getId();
      } catch (Exception $e) {
        echo "Error creating category: " . $e->getMessage();
        exit;
      }
    }    
    return $id_category;
  }

  /**
     * Retrieve Category ID if exists (Search by Name).
     *
     * @param string $categoryName Category name
     *
     * @return int Category ID
  */
  function getCategory ($categoryName){
    if(empty($categoryName))
      return null;

    // Load the category collection filtered by name
    $categoryCollection = Mage::getModel('catalog/category')
      ->getCollection()
      ->addAttributeToFilter('name', $categoryName)
      ->setPageSize(1);
    
    if ($categoryCollection->getSize()) {
      $category = $categoryCollection->getFirstItem();
      return $category->getId();
    }

    return null;
  }

  /**
     * Adds current Attribute Set as a new Object to the database, based on a Default Set.
     *
     * @param string $attributeSetName Attribute Set name
     * @param string $baseAttributeSetName Base Attribute Set name
     *
     * @return int Attribute Set ID
  */
  function addAttributeSet ($attributeSetName, $baseAttributeSetName = 'Default'){
    $setup = new Mage_Eav_Model_Entity_Setup('core_setup');
    $setup->startSetup();

    $entityTypeId = (int) $setup->getEntityTypeId('catalog_product');
    
    try {
      $baseAttributeSetId = (int) $setup->getAttributeSetId($entityTypeId, $baseAttributeSetName);
    }
    catch (Exception $e) {
      echo 'Error: ' . $e->getMessage() . PHP_EOL;
      echo 'Base attribute set does not exist.';
      exit;
    }

    if(empty($attributeSetName))
      return null;
    
    if (!$attributeSetID = getAttributeSet($attributeSetName)) {
      $baseAttributeSetId = (int) $setup->getAttributeSetId($entityTypeId, 'Default');

      $attributeSet = Mage::getModel('eav/entity_attribute_set')
          ->setEntityTypeId($entityTypeId)
          ->setAttributeSetName($attributeSetName)
          ->setParentId($baseAttributeSetId);

      try {
        $attributeSet->validate();
        $attributeSet->save();
        
        // Load the attribute group collection
        $baseAttributeGroups = Mage::getModel('eav/entity_attribute_group')
          ->getCollection()
          ->setAttributeSetFilter($baseAttributeSetId)
          ->setSortOrder()
          ->load();

        // Create attribute groups within the new attribute set
        foreach ($baseAttributeGroups as $baseAttributeGroup) { 
          $attributeGroup = Mage::getModel('eav/entity_attribute_group')
            ->setAttributeGroupName($baseAttributeGroup->getAttributeGroupName())
            ->setAttributeSetId($attributeSet->getId());
          
          try {
            $attributeGroup->save();

            // Load the attribute collection
            $baseAttributes = Mage::getModel('eav/entity_attribute')
              ->getResourceCollection()
              ->setAttributeGroupFilter($baseAttributeGroup->getId());

            // Create attribute groups within the new attribute set
            foreach ($baseAttributes as $baseAttribute) {  
              try {
                $setup->addAttributeToGroup($entityTypeId, $attributeSet->getId(), $attributeGroup->getId(), $baseAttribute->getId());
              } catch (Exception $e) {
                echo 'Error linking attribute: ' . $e->getMessage();
                exit;
              }
            }
          } catch (Exception $e) {
            echo 'Error creating attribute group: ' . $e->getMessage();
            exit;
          }
        }
        $setup->endSetup();
        return (int) $attributeSet->getId();
      } catch (Exception $e) {
          echo 'Error creating attribute set: ' . $e->getMessage();
          exit;
      }
    } 
    
    $setup->endSetup();
    return $attributeSetID;
  }

  /**
     * Retrieve Attribute Set ID if exists (Search by Name).
     *
     * @param string $attributeSetName Attribute Set name
     *
     * @return int Attribute Set ID
  */
  function getAttributeSet ($attributeSetName){
    $setup = new Mage_Eav_Model_Entity_Setup('core_setup');
    $setup->startSetup();

    $entityTypeId = (int) $setup->getEntityTypeId('catalog_product');
 
    if(empty($attributeSetName))
      return null;
      
    $attributeSet = Mage::getModel('eav/entity_attribute_set')
      ->getCollection()
      ->addFieldToFilter('entity_type_id', $entityTypeId)
      ->addFieldToFilter('attribute_set_name', $attributeSetName)
      ->getFirstItem();
      
    $setup->endSetup();
    return $attributeSet->getId() ? $attributeSet->getId() : null;
  }

  /**
     * Adds current Attribute Group as a new Object to the database.
     *
     * @param int $attributeSetID Attribute Set ID
     * @param string $attributeGroupName Attribute Group name
     *
     * @return int Attribute Group ID
  */
  function addAttributeGroup ($attributeSetID, $attributeGroupName){
    if(empty($attributeGroupName) || !$attributeSetID)
      return null;

    if (!$attributeGroupID = getAttributeGroup($attributeSetID, $attributeGroupName)) {
      $attributeGroup = Mage::getModel('eav/entity_attribute_group')
        ->setAttributeGroupName($attributeGroupName)
        ->setAttributeSetId($attributeSetID);

      try {
        $attributeGroup->save();
        return $attributeGroup->getId();
      } catch (Exception $e) {
        echo 'Error creating attribute group: ' . $e->getMessage();
        exit;
      }
    }

    return $attributeGroupID;
  }

  /**
     * Retrieve Attribute Group ID if exists (Search by Name).
     *
     * @param int $attributeSetID Attribute Set ID
     * @param string $attributeGroupName Attribute group name
     *
     * @return int Attribute Group ID
  */
  function getAttributeGroup ($attributeSetID, $attributeGroupName){
    if(empty($attributeGroupName) || !$attributeSetID)
      return null;
    
    // Load the attribute group collection
    $attributeGroup = Mage::getModel('eav/entity_attribute_group')
      ->getCollection()
      ->setAttributeSetFilter($attributeSetID)
      ->addFieldToFilter('attribute_group_name', $attributeGroupName)
      ->getFirstItem();
    
    return $attributeGroup->getId() ? $attributeGroup->getId() : null;
  } 
  
  /**
     * Adds current Attribute as a new Object to the database.
     *
     * @param int $attributeSetID Attribute Set ID
     * @param int $attributeGroupID Attribute Group ID
     * @param string $attributeName Attribute name
     *
     * @return object Attribute Object
  */
  function addAttribute ($attributeSetID, $attributeGroupID, $attributeName){
    if(empty($attributeName))
      return null;
    
    $setup = new Mage_Eav_Model_Entity_Setup('core_setup');
    $setup->startSetup();

    $entityTypeId = (int) $setup->getEntityTypeId('catalog_product');
    
    // Load the attribute collection
    $attribute = Mage::getModel('eav/entity_attribute')
      ->getResourceCollection()
      ->setAttributeGroupFilter($attributeGroupID)
      ->addFieldToFilter('attribute_code', str_replace(" ", "-", strtolower($attributeName)) . '_toptex')
      ->getFirstItem();

    if (!$attributeID = getAttribute($attributeGroupID, $attributeName)) {
      // Create the attribute
      $attribute = Mage::getModel('catalog/resource_eav_attribute');
      $attribute->setAttributeCode(str_replace(" ", "-", strtolower($attributeName)) . '_toptex');
      $attribute->setEntityTypeId($entityTypeId);
      $attribute->setFrontendInput('select');
      $attribute->setBackendType('varchar');
      $attribute->setFrontendLabel($attributeName);
      $attribute->setAttributeSetId($attributeSetID);
      $attribute->setAttributeGroupId($attributeGroupID);
      $attribute->setIsRequired(true);
      $attribute->setIsUserDefined(true);
      try {
        $attribute->save();
        return $attribute;
      } catch (Exception $e) {
        echo 'Error creating attribute: ' . $e->getMessage();
        exit;
      }
    }  
    
    $setup->endSetup();

    return Mage::getModel('eav/entity_attribute')->load($attributeID);
  }

  /**
     * Retrieve Attribute ID if exists (Search by Name).
     *
     * @param int $attributeGroupID Attribute Group ID
     * @param string $attributeName Attribute name
     *
     * @return int Attribute ID
  */
  function getAttribute ($attributeGroupID, $attributeName){
    if(empty($attributeName) || !$attributeGroupID)
      return null;

    // Load the attribute collection
    $attribute = Mage::getModel('eav/entity_attribute')
      ->getResourceCollection()
      ->setAttributeGroupFilter($attributeGroupID)
      ->addFieldToFilter('attribute_code', str_replace(" ", "-", strtolower($attributeName)) . '_toptex')
      ->getFirstItem();

    return $attribute->getId() ? $attribute->getId() : null;
  }

  /**
     * Adds current Attribute Option as a new Object to the database.
     *
     * @param object $attribute Attribute object
     * @param string $attributeValue Option value
     *
     * @return int Option ID
  */
  function addAttributeOption ($attribute, $attributeValue){
    if(empty($attributeValue))
      return null;

    if (!$attributeOptionID = getAttributeOption($attribute->getId(), $attributeValue)) {
      // Create a new attribute option value
      $attributeOption = Mage::getModel('eav/entity_attribute_option');
      $attributeOption->setAttributeId($attribute->getId());
      $attributeOption->setSortOrder(0);
      try {
        $attributeOption->save();
        // Save the option value for each store view

        $storeIds = Mage::getModel('core/store')->getCollection()->getAllIds();
        $connection = Mage::getSingleton('core/resource')->getConnection('core_write');
        
        foreach ($storeIds as $storeId) {
          $connection->insert('mg_eav_attribute_option_value', [
                'option_id' => $attributeOption->getId(),
                'store_id' => $storeId,
                'value' => $attributeValue
            ]);
        }

        if(empty($attribute->getDefaultValue())){
          $connection->update('mg_eav_attribute', [
            'default_value' => $attributeOption->getId()
          ], "attribute_id = " . $attribute->getId());
        }

        return $attributeOption->getId();
      } catch (Exception $e) {
        echo 'Error occurred while adding the option: ' . $e->getMessage();
      }
    } 

    return $attributeOptionID;
  }

  /**
     * Retrieve Attribute Option ID if exists (Search by option value).
     *
     * @param int $attributeID Attribute ID
     * @param string $attributeValue Option value
     *
     * @return int Option ID
  */
  function getAttributeOption ($attributeID, $attributeValue){
    if(empty($attributeValue) || !$attributeID)
      return null;

    // Check if the option exists
    $attributeOption = Mage::getModel('eav/entity_attribute_option')        
      ->getCollection()
      ->setPositionOrder('asc', true)
      ->setAttributeFilter($attributeID)
      ->addFieldToFilter('store_id', 0)
      ->addFieldToFilter('value', $attributeValue)
      ->setPageSize(1)
      ->setCurPage(1)
      ->getFirstItem();

    return $attributeOption->getId() ? $attributeOption->getId() : null;
  }

  /**
     * Retrieve Attribute Option ID if exists (Search by option value).
     *
     * @param int $productID Product ID
     * @param boolean $isConfigurable Set to "true" if if product is configurable. Default is simple product.
     *
     * @return boolean true
  */
  function updateProductStock ($productID, $isConfigurable = false){
    // Get the stock item associated with the product
    $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($productID);
                
    // Create a new stock item if it doesn't exist
    if (!$stockItem->getId()) {
      $stockItem->setData('product_id', $productID);
      $stockItem->setData('stock_id', 1); // Replace with the appropriate stock ID if necessary
    }

    // Set manage stock and is_in_stock values
    $stockItem->setData('manage_stock', 1); // 1 = Active, 0 = Inactive
    $stockItem->setData('is_in_stock', 1); // 1 = In stock, 0 = Out of stock

    // Save the stock item
    $stockItem->save();
    
    if(!$isConfigurable){
      $connection = Mage::getSingleton('core/resource')->getConnection('core_write');
      $connection->update('mg_cataloginventory_stock_item', [
        'qty' => 1
      ], "item_id = " . $stockItem->getId());
    }

    return true;
  }

  /**
     * Retrieve Attribute Option ID if exists (Search by option value).
     *
     * @param object $curlInstance Instance of a curl conexion previously stablished
     * @param array $imagesURL A list with all the images URLs to be downloaded.
     * @param string $downloadFolder The path where the images will be saved (Leave blank to use default Magento folder).
     *
     * @return array Images download path
  */
  function downloadImageFromURL($curlInstance, $imagesURL, $downloadFolder = ''){
    // Download the file using cURL
    $tmpImgPath = array();
    curl_setopt_array($curlInstance, array(
      CURLOPT_RETURNTRANSFER => 1,
      CURLOPT_FOLLOWLOCATION => 1,
      CURLOPT_SSL_VERIFYPEER => 0,
      CURLOPT_SSL_VERIFYHOST => 0,
      CURLOPT_TIMEOUT => 120
    ));

    if(empty($downloadFolder))
      $downloadFolder = Mage::getBaseDir('media') . DS . 'import';
        
    // Upload images
    foreach ($imagesURL as $imageURL) {
      $fileTransferUrl = $imageURL;

      // Download the file using cURL
      curl_setopt($curlInstance, CURLOPT_URL, $fileTransferUrl);
      curl_setopt($curlInstance, CURLOPT_HEADER, 1);
      curl_setopt($curlInstance, CURLOPT_NOBODY, 1);
      $header = curl_exec($curlInstance);

      // Check for errors
      if ($header === false) {
        echo 'Error getting the image: ' . curl_error($curlInstance);
      } 
      else {
        $filename = '';
        if (preg_match('/filename="(.*?)"/', $header, $matches)) {
            $filename = $matches[1];
        }

        if(!file_exists($downloadFolder . DS . $filename)){
          // Download the file and save it with the extracted filename
          curl_setopt($curlInstance, CURLOPT_HEADER, 0);
          curl_setopt($curlInstance, CURLOPT_NOBODY, 0);
          $fileData = curl_exec($curlInstance);

          if ($fileData !== false) {
            $tmpImgPath[] = $downloadFolder . DS . $filename;
            file_put_contents(end($tmpImgPath), $fileData);
          } else {
              echo "Failed to download the image using the File Transfer URL: " . $fileTransferUrl . "\n";
          }
        }
        else
          $tmpImgPath[] = $downloadFolder . DS . $filename;  
      }
    }

    return $tmpImgPath;
  }