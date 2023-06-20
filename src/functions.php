<?php
  // Function to generate URL Key from category name
  function generateUrlKey($name) {
    $urlKey = strtolower($name);
    $urlKey = preg_replace('/[^a-z0-9]+/', '-', $urlKey);
    $urlKey = trim($urlKey, '-');
    return $urlKey;
  }

  // Function create a new category by name, or retrieve the ID if a category already exists
  function createOrRetrieveCategory ($categoryName, $parentId = 1){
    if(empty($categoryName))
      return "1";

    // Load the category collection filtered by name
    $categoryCollection = Mage::getModel('catalog/category')
      ->getCollection()
      ->addAttributeToFilter('name', $categoryName)
      ->setPageSize(1);
    
    if (!$categoryCollection->getSize()) {
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
      } catch (Exception $e) {
        echo "Error creating category: " . $e->getMessage();
        exit;
      }
    }
    else
      $category = $categoryCollection->getFirstItem();
    
    return $category->getId();
  }

  // Function create a new Attribute Set by name, or retrieve the ID if the Attribute Set already exists
  function createOrRetrieveAttributeSet ($attributeSetName, $baseAttributeSetName = 'Default'){
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
      return $baseAttributeSetId;
      
    $attributeSet = Mage::getModel('eav/entity_attribute_set')
      ->getCollection()
      ->addFieldToFilter('entity_type_id', $entityTypeId)
      ->addFieldToFilter('attribute_set_name', $attributeSetName)
      ->getFirstItem();
    
    if (!$attributeSet->getId() || false) {
      $baseAttributeSetId = (int) $setup->getAttributeSetId($entityTypeId, 'Default');

      $attributeSet = Mage::getModel('eav/entity_attribute_set')
          ->setEntityTypeId($entityTypeId)
          ->setAttributeSetName($attributeSetName)
          ->setParentId($baseAttributeSetId);

      try {
        $attributeSet->validate();
        $attributeSet->save();
    
        // Assign the new attribute set as the default for the entity type
        // $setup->addAttributeSet($entityTypeId, $attributeSet->getId(), $baseAttributeSetId);
        
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
      } catch (Exception $e) {
          echo 'Error creating attribute set: ' . $e->getMessage();
          exit;
      }
    } 
    
    $setup->endSetup();
    return (int) $attributeSet->getId();
  }

  // Function create a new Attribute Set by name, or retrieve the ID if the Attribute Set already exists
  function createOrRetrieveAttribute ($attributeSetID, $attributeValue, $attributeName, $attributeGroupName = 'TopTex Extra'){
    $setup = new Mage_Eav_Model_Entity_Setup('core_setup');
    $setup->startSetup();

    $entityTypeId = (int) $setup->getEntityTypeId('catalog_product');
    
    // Load the attribute group collection
    $attributeGroup = Mage::getModel('eav/entity_attribute_group')
      ->getCollection()
      ->setAttributeSetFilter($attributeSetID)
      ->addFieldToFilter('attribute_group_name', $attributeGroupName)
      ->getFirstItem();

    if (!$attributeGroup->getId()) {
      $attributeGroup = Mage::getModel('eav/entity_attribute_group')
        ->setAttributeGroupName($attributeGroupName)
        ->setAttributeSetId($attributeSetID);

      try {
        $attributeGroup->save();
      } catch (Exception $e) {
        echo 'Error creating attribute group: ' . $e->getMessage();
        exit;
      }
    }

    // Load the attribute collection
    $attribute = Mage::getModel('eav/entity_attribute')
      ->getResourceCollection()
      ->setAttributeGroupFilter($attributeGroup->getId())
      ->addFieldToFilter('attribute_code', str_replace(" ", "-", strtolower($attributeName)) . '_toptex')
      ->getFirstItem();

    if (!$attribute->getId()) {
      // Create the attribute
      $attribute = Mage::getModel('catalog/resource_eav_attribute');
      $attribute->setAttributeCode(str_replace(" ", "-", strtolower($attributeName)) . '_toptex');
      $attribute->setEntityTypeId($entityTypeId);
      $attribute->setFrontendInput('select');
      $attribute->setBackendType('varchar');
      $attribute->setFrontendLabel($attributeName);
      $attribute->setAttributeSetId($attributeSetID);
      $attribute->setAttributeGroupId($attributeGroup->getId());
      $attribute->setIsRequired(true);
      $attribute->setIsUserDefined(true);
      try {
        $attribute->save();
      } catch (Exception $e) {
        echo 'Error creating attribute: ' . $e->getMessage();
        exit;
      }
    }  

    // Check if the option exists
    $attributeOption = Mage::getModel('eav/entity_attribute_option')        
      ->getCollection()
      ->setPositionOrder('asc', true)
      ->setAttributeFilter($attribute->getId())
      ->addFieldToFilter('store_id', 0)
      ->addFieldToFilter('value', $attributeValue)
      ->setPageSize(1)
      ->setCurPage(1)
      ->getFirstItem();

    if (!$attributeOption->getId()) {
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
      } catch (Exception $e) {
        echo 'Error occurred while adding the option: ' . $e->getMessage();
      }
    } 
    
    $setup->endSetup();

    return $attributeOption;
  }