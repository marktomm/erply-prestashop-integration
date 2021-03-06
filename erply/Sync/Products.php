<?php

include_once(_PS_MODULE_DIR_ . '/erply/Sync/Abstract.php');
include_once(_PS_MODULE_DIR_ . '/erply/ErplyFunctions.class.php');
include_once(_PS_ROOT_DIR_ . '/images.inc.php');

class Erply_Sync_Products extends Erply_Sync_Abstract
{
    protected static $_erplyChangedProductsIds = array();
    protected static $_prestaChangedProductsIds;
    
    protected static $_prestaManufAry;
    protected static $_prestaSuppliersAry;
    
    /**
     * Sync all Products both ways.
     * 
     * @return integer - total products synchronized
     */
    public static function syncAll($ignoreLastTimestamp = false)
    {
        // It is important to import before export because
        // we need all ERPLY changed product IDs before making
        // export. In import procedure we receive all ERPLY products
        // anyway so we dont need to make extra requests to retreive
        // these IDs.
        $output = self::importAll($ignoreLastTimestamp);
        $output .= self::exportAll($ignoreLastTimestamp);
        
        // Set now as last sync time.
        //		ErplyFunctions::setProductLastSyncTS(time());
        
        return $output;
    }
    
    /**
     * Import all ERPLY categories.
     * 
     * @return integer - Nr of categories imported.
     */
    public static function importAll($ignoreLastTimestamp = false)
    {
        ErplyFunctions::log('Start Product Import.');
        
        if($ignoreLastTimestamp == true) {
            Configuration::set('ERPLY_ERPLY_PRODUCTS_LS_TS', 0);
        } else {
            //TODO: this is as hack to always import all products
            Configuration::set('ERPLY_ERPLY_PRODUCTS_LS_TS', 0);
        }
        
        $output = Utils::displayWarning('Product import not implemented');
        return $output;
        
        // Get object priority
        $objectPriority = self::getObjectPriority();
        
        // Load Presta Products changed since last sync.
        self::getPrestaChangedProductsIds();
        
        // Init changed products
        self::$_erplyChangedProductsIds = array();
        
        // Init ERPLY API request
        $erplyLastSyncTS  = ErplyFunctions::getLastSyncTS('ERPLY_PRODUCTS');
        $apiRequestPageNr = 1;
        $apiRequest       = array(
            'getStockInfo' => 1,
            'changedSince' => $erplyLastSyncTS,
            'orderBy' => 'changed',
            'orderByDir' => 'asc',
            'recordsOnPage' => self::$erplyApiRecordsOnPage,
            'pageNo' => $apiRequestPageNr,
            'status' => 'ALL_EXCEPT_ARCHIVED'
        );
        
        do {
            // Import page from ERPLY
            $erplyProducts = ErplyFunctions::getErplyApi()->getProducts($apiRequest);
            if(is_array($erplyProducts)) {
                foreach($erplyProducts as $erplyProduct) {
                    // Add to ERPLY changed products cache.
                    self::$_erplyChangedProductsIds[] = $erplyProduct['productID'];
                    
                    // Find mapping
                    $mappingObj = self::getProductMapping('erply_id', $erplyProduct['productID']);
                    
                    
                    if(!is_null($mappingObj)) {
                        // Mapping exists (Product IS in sync), Update.
                        
                        // Check if same Product has also changed in Presta
                        if(in_array($mappingObj->getPrestaId(), self::$_prestaChangedProductsIds)) {
                            // Product has changed both in ERPLY and in Presta.
                            // Check priority.
                            if($objectPriority == 'ERPLY') {
                                // Override Presta changes with ERPLY
                                list($prestaProduct, $mappingObj) = self::updatePrestaProduct($mappingObj->getPrestaId(), $erplyProduct, $mappingObj);
                                $output .= Utils::displayConfirmation('Override Presta Product with erply data. Presta Product id: ' . $mappingObj->getPrestaId());
                            }
                            // else do nothing
                        } else {
                            // Product has not changed in Presta so update with ERPLY data.
                            list($prestaProduct, $mappingObj) = self::updatePrestaProduct($mappingObj->getPrestaId(), $erplyProduct, $mappingObj);
                            $output .= Utils::displayConfirmation('Update Presta Product with erply data. Presta Product id: ' . $mappingObj->getPrestaId());
                        }
                    } else {
                        // Mapping not found (Product NOT in sync), Create.
                        
                        // Create product
                        list($prestaProduct, $mappingObj) = self::createPrestaProduct($erplyProduct);
                        $output .= Utils::displayConfirmation('Create Presta Product with erply data. Erply Product id: ' . $erplyProduct['productID'] . ' name: ' . $erplyProduct['name']);
                    }
                    
                    // Update last sync ts
                    $newSyncTS = !empty($erplyProduct['lastModified']) ? $erplyProduct['lastModified'] : $erplyProduct['added'];
                    ErplyFunctions::setLastSyncTS('ERPLY_PRODUCTS', $newSyncTS);
                }
            }
            
            // Get next page
            $apiRequestPageNr++;
            $apiRequest['pageNo'] = $apiRequestPageNr;
        } while(!empty($erplyProducts));
        
        ErplyFunctions::log('End Product Import. Imported ' . $return . ' products.');
        return $output;
    }
    
    /**
     * Export Presta products.
     * 
     * @return integer - Nr of products exported.
     */
    public static function exportAll($ignoreLastTimestamp = false)
    {
        ErplyFunctions::log('Start Product Export.');
        $output = '';
        
        $erplyLastSyncTS = ErplyFunctions::getLastSyncTS('PRESTA_PRODUCTS'); // reset in case of error
        
        $run        = (is_int(Configuration::get('ERPLY_EXPORT_PRODUCTS_LAST_RUN')) ? Configuration::get('ERPLY_EXPORT_PRODUCTS_LAST_RUN') : 0);
        $productIds = self::getPrestaChangedProductsIds(); // MAJOR: Check if buying product will change its date_upd
        
        if($run > count($productIds)) {
            $msg = 'Product iterator(' . $run . ') is bigger than changed product id count(' . count($productIds) . ')';
            $output .= self::logAndReturn($msg, 'ferr');
            throw new Erply_Exception('FATAL ERROR: ' . $msg);
        }
        
        if($run) {
            $output .= self::logAndReturn('Product Export was previously interrupted. Current product iterator: ' . $run, 'warn');
            
            // there was an interruption. The last product was half synchronized mb. delete it from erply and mapping
            $output .= self::deleteParentProduct($productIds[$run]);
        }
        
        try {
            for(; $run < count($productIds);) {
                $idProduct = $productIds[$run];
                ErplyFunctions::log('Product export iterator: ' . $run);
                $output .= self::doExport($idProduct);
                // if($run == 10) {
                // 	$e = new Erply_Exception();
                // 	$e->setData('code', 1002);
                // 	throw $e;
                // }
                ++$run;
            }
            Configuration::updateValue('ERPLY_EXPORT_PRODUCTS_LAST_RUN', 0);
        }
        catch(Erply_Exception $e) {
            // it should be only 1002
            ErplyFunctions::setLastSyncTS('PRESTA_PRODUCTS', $erplyLastSyncTS);
            Configuration::updateValue('ERPLY_EXPORT_PRODUCTS_LAST_RUN', $run);
            $output .= $e->getData('output');
            if($e->getData('code') == 1002) {
                $output .= self::logAndReturn('Maximum requests reached. Do not add or modify any product. Wait an hour and resume. Changed products index: ' . $run, 'warn');
            } else {
                $output .= self::logAndReturn('Error code: ' . $e->getData('code') . ' message: ' . $e->getData('message'), 'err');
            }
        }
        
        if(!$run) {
            $output .= self::logAndReturn('No Products changed since last export');
        }
        
        $output .= self::logAndReturn($run . ' Products traversed');
        return $output;
    }
    
    public static function exportSingle($idProduct)
    {
        ErplyFunctions::log('Start Single Product Export. id: ' . $idProduct);
        $output = '';
        
        try {
            if(FALSE !== array_search($idProduct, self::getPrestaChangedProductsIds())) {
                $output .= self::doExport($idProduct);
            } else {
                $output .= self::logAndReturn('Product not changed since last export');
            }
        }
        catch(Erply_Exception $e) {
            // it should be only 1002
            $output .= $e->getData('output');
            if($e->getData('code') == 1002) {
                $output .= self::logAndReturn('Maximum requests reached. Wait an hour and try again.', 'warn');
            } else {
                $output .= self::logAndReturn('Error code: ' . $e->getData('code') . ' message: ' . $e->getData('message'), 'err');
            }
            $e->setData('output', $output);
            throw $e;
        }
        
        return $output;
    }
    
    protected static function doExport($idProduct)
    {
        $output = '';
        
        $output .= self::exportSingleParentProduct($idProduct);
        
        $prestaLocaleId = ErplyFunctions::getPrestaLocaleId();
        $product        = new Product($idProduct, true, $prestaLocaleId);
        ErplyFunctions::log('get id_product_attribute -s for Product ref: ' . $product->reference);
        $productAttributesGroups = $product->getAttributesGroups($prestaLocaleId);
        $idProductAttributeIds   = array();
        foreach($productAttributesGroups as $attrsGrp) {
            $idProductAttribute = $attrsGrp['id_product_attribute'];
            if(FALSE === array_search($idProductAttribute, $idProductAttributeIds)) {
                $idProductAttributeIds[] = $idProductAttribute;
                ErplyFunctions::log('Found id_product_attribute: ' . $idProductAttribute);
            }
        }
        
        foreach($idProductAttributeIds as $idPrAttr) {
            $output .= self::exportSingleProduct($idProduct, $idPrAttr);
        }
        
        $output .= self::logAndReturn('Export Presta Product complete. Presta Product id: ' . $idProduct);
        return $output;
    }
    
    public static function exportSingleParentProduct($prestaProductId)
    {
        $output = '';
        
        $prestaLocaleId = ErplyFunctions::getPrestaLocaleId();
        
        // Get object priority
        $objectPriority = self::getObjectPriority();
        
        $product = new Product($prestaProductId, true, $prestaLocaleId);
        
        // Find mapping
        $mappingObj = self::getParentProductMapping('local_id', $prestaProductId);
        
        // $ok = true;
        if(!is_null($mappingObj)) {
            // Mapping found, Product IS in sync
            // Check if same Product has also changed in ERPLY
            if(self::erplyProductHasChanged($mappingObj->getErplyId())) {
                // If object priority is Presta then export
                if($objectPriority == 'Presta') {
                    // Update ERPLY product
                    self::updateErplyParentProduct($product, $mappingObj);
                } // else do nothing
            } else {
                // Product only changed in Presta, export.
                // Update ERPLY product
                self::updateErplyParentProduct($product, $mappingObj);
            }
        } else {
            // Mapping not found, Product NOT in sync.
            // Load Presta product
            self::updateErplyParentProduct($product);
        }
        
        // if(!$ok) {
        ErplyFunctions::setLastSyncTS('PRESTA_PRODUCTS', strtotime($product->date_upd));
        // }
        
        return $output;
    }
    
    public static function exportSingleProduct($prestaProductId, $idProductAttribute)
    {
        $output = '';
        
        $prestaLocaleId = ErplyFunctions::getPrestaLocaleId();
        
        // Get object priority
        $objectPriority = self::getObjectPriority();
        
        // Unset
        $product                      = new Product($prestaProductId, true, $prestaLocaleId);
        $productAttributeCombinations = $product->getAttributeCombinationsById($idProductAttribute, $prestaLocaleId);
        
        // Find mapping
        $mappingObj = ErplyMapping::getMapping('Product', 'local_id', $idProductAttribute);
        
        if(!is_null($mappingObj)) {
            // Mapping found, Product IS in sync
            // Check if same Product has also changed in ERPLY
            if(self::erplyProductHasChanged($mappingObj->getErplyId())) {
                // If object priority is Presta then export
                if($objectPriority == 'Presta') {
                    // Update ERPLY product
                    self::updateErplyProduct($product, $productAttributeCombinations, $mappingObj);
                }
            } else {
                // Product only changed in Presta, export.
                // Update ERPLY product
                self::updateErplyProduct($product, $productAttributeCombinations, $mappingObj);
            }
        } else {
            // Mapping not found, Product NOT in sync
            // Load Presta product
            self::updateErplyProduct($product, $productAttributeCombinations);
        }
        
        return $output;
    }
    
    /**
     * Update Presta Product with ERPLY product data.
     * 
     * @param integer $prestaProductId
     * @param array $erplyProduct
     * @param ErplyMapping $mapping
     * @return Product - Presta Product
     */
    private static function updatePrestaProduct($prestaProductId, $erplyProduct, $mapping)
    {
        ErplyFunctions::log('Updating product ' . $erplyProduct['code'] . ' in Presta.');
        
        $localeId = ErplyFunctions::getPrestaLocaleId();
        
        // Load Presta Category if ID presented.
        $prestaProduct = new Product($prestaProductId);
        
        // If product has set to hidden then dont change any further.
        if($erplyProduct['displayedInWebshop'] == 0) {
            if($prestaProduct->active == 1) {
                $prestaProduct->active = 0;
                $prestaProduct->update();
            }
            return array(
                $prestaProduct,
                $mapping
            );
        }
        
        // Name
        if(!empty($erplyProduct['name'])) {
            $name                           = self::prestaSafeName($erplyProduct['name'], 128);
            $prestaProduct->name[$localeId] = $name;
        }
        
        // Is active
        $prestaProduct->active = (int) $erplyProduct['displayedInWebshop'];
        
        // Indexed
        $prestaProduct->indexed = 1;
        
        // Reduction times
        $reductionTime                 = date('Y-m-d H:i:s', $erplyProduct['added']);
        $prestaProduct->reduction_from = $reductionTime;
        $prestaProduct->reduction_to   = $reductionTime;
        
        // Code. Presta code cannot be more than 32 characters logn.
        $prestaProduct->reference = substr($erplyProduct['code'], 0, 32);
        
        // EAN
        if(!empty($erplyProduct['code2']) && Validate::isEan13($erplyProduct['code2'])) {
            $prestaProduct->ean13 = $erplyProduct['code2'];
        }
        
        // Description
        $shortDescription                            = !empty($erplyProduct['description']) ? $erplyProduct['description'] : '';
        $shortDescription                            = mb_substr($shortDescription, 0, 400);
        $longDescription                             = !empty($erplyProduct['longdesc']) ? $erplyProduct['longdesc'] : '';
        $prestaProduct->description_short[$localeId] = $shortDescription;
        $prestaProduct->description[$localeId]       = $longDescription;
        
        // Categories
        $categoryMapping = self::getCategoryMapping('erply_id', $erplyProduct['groupID']);
        if(!is_null($categoryMapping)) {
            $prestaProduct->id_category         = $categoryMapping->getPrestaId();
            $prestaProduct->id_category_default = $categoryMapping->getPrestaId();
            
            // Make sure product is in default category. If not then add to.
            $productCategoriesAry = array();
            foreach(Scene::getIndexedCategories($prestaProduct->id) as $index) {
                $productCategoriesAry[] = $index['id_category'];
            }
            
            if(!in_array($prestaProduct->id_category_default, $productCategoriesAry)) {
                $productCategoriesAry[] = $prestaProduct->id_category_default;
                $prestaProduct->updateCategories($productCategoriesAry);
            }
        }
        
        // Link rewrite
        $link_rewrite                           = Tools::link_rewrite($prestaProduct->name[$localeId]);
        $prestaProduct->link_rewrite[$localeId] = $link_rewrite;
        
        // Vatrate
        $prestaProduct->id_tax_rules_group = intval(self::getTaxIdByRate(floatval($erplyProduct['vatrate'])));
        $prestaProduct->id_tax             = intval(self::getTaxIdByRate(floatval($erplyProduct['vatrate'])));
        
        // Prices
        $prestaProduct->price  = $erplyProduct['price'];
        $prestaProduct->weight = !empty($erplyProduct['netWeight']) ? $erplyProduct['netWeight'] : 0;
        
        // Manufacturer
        if(!empty($erplyProduct['brandName'])) {
            $brandName                        = self::prestaSafeName($erplyProduct['brandName']);
            $prestaManuf                      = self::getOrAddPrestaManufacturer($brandName);
            $prestaProduct->id_manufacturer   = $prestaManuf['id_manufacturer'];
            $prestaProduct->manufacturer_name = $prestaManuf['name'];
        } else {
            $prestaProduct->id_manufacturer   = null;
            $prestaProduct->manufacturer_name = null;
        }
        
        // Supplier
        if(!empty($erplyProduct['supplierName'])) {
            $supplierName                 = self::prestaSafeName($erplyProduct['supplierName']);
            $prestaSupplier               = self::getOrAddPrestaSupplier($supplierName);
            $prestaProduct->id_supplier   = $prestaSupplier['id_supplier'];
            $prestaProduct->supplier_name = $prestaSupplier['name'];
        } else {
            $prestaProduct->id_supplier   = null;
            $prestaProduct->supplier_name = null;
        }
        
        // Qty. Combine all warehouses. 
        $erplyQuantity = self::getErplyQuantity($erplyProduct);
        if($erplyQuantity < 0) {
            $erplyQuantity = 0;
        }
        // Decimal quantities are not allowed.
        $erplyQuantity = round((float) $erplyQuantity, 0);
        // Quantity can have max 6 digits.
        if($erplyQuantity > 999999) {
            $erplyQuantity = 999999;
        }
        
        // Update
        if(!$prestaProduct->update()) {
            ErplyFunctions::log('Error: Failed to update product "' . $erplyProduct['code'] . '" in Prestashop');
        }
        
        // Set quantity
        self::setPrestaProductQuantity($prestaProduct, $erplyQuantity);
        
        /*
         * Update images
         */
        
        $erplyImagesAry     = (isset($erplyProduct['images']) && is_array($erplyProduct['images'])) ? $erplyProduct['images'] : array();
        $currentMappingsAry = $mapping->getInfo('images');
        if(!is_array($currentMappingsAry))
            $currentMappingsAry = array();
        
        // Update only if current mappings exist or ERPLY images exist.
        if(count($erplyImagesAry) || count($currentMappingsAry)) {
            ErplyFunctions::log('Starting to update images for product "' . $erplyProduct['code']);
            $prestaImagesAry = Image::getImages(intval($localeId), intval($prestaProduct->id));
            $newMappingsAry  = array();
            $coverRemoved    = false;
            
            // Create id=>index arrays for better search results
            $erplyImagesIdsAry = array();
            foreach($erplyImagesAry as $i => $erplyImage) {
                $erplyImagesIdsAry[$erplyImage['pictureID']] = $i;
            }
            $prestaImagesIdsAry = array();
            foreach($prestaImagesAry as $i => $prestaImage) {
                $prestaImagesIdsAry[$prestaImage['id_image']] = $i;
            }
            
            // Go through current mappings
            foreach($currentMappingsAry as $prestaImageId => $erplyImageId) {
                // If image exists in ERPLY
                if(array_key_exists($erplyImageId, $erplyImagesIdsAry)) {
                    // If image does not exists in Presta
                    if(!array_key_exists($prestaImageId, $prestaImagesIdsAry)) {
                        // Get ERPLY image.
                        $i          = $erplyImagesIdsAry[$erplyImageId];
                        $erplyImage = $erplyImagesAry[$i];
                        
                        // Create Presta image
                        $prestaImage = self::createPrestaImage($prestaProduct, $erplyImage);
                        
                        // Add to mappings
                        if($prestaImage !== false) {
                            $newMappingsAry[$prestaImage->id] = $erplyImageId;
                        }
                    } else {
                        // Image does exist in Presta
                        
                        // Add to new mappings
                        $newMappingsAry[$prestaImageId] = $erplyImageId;
                    }
                    
                    // Remove from ERPLY list because we need to create new images from
                    // ERPLY list later on.
                    $i = $erplyImagesIdsAry[$erplyImageId];
                    unset($erplyImagesAry[$i]);
                    unset($erplyImagesIdsAry[$erplyImageId]);
                } else {
                    // Image does not exist in ERPLY
                    
                    // If image exists in Presta
                    if(array_key_exists($prestaImageId, $prestaImagesIdsAry)) {
                        // Remove from Presta
                        $i              = $prestaImagesIdsAry[$prestaImageId];
                        $prestaImageAry = $prestaImagesAry[$i];
                        $prestaImage    = new Image($prestaImageAry['id_image']);
                        $prestaImage->delete();
                        
                        // Remove fromo Presta images arrays.
                        unset($prestaImagesAry[$i]);
                        unset($prestaImagesIdsAry[$prestaImageId]);
                        
                        if((int) $prestaImageAry['cover'] > 0) {
                            // Set $coverRemoved to true because we must add a new cover after
                            // all images are synced.
                            $coverRemoved = true;
                        }
                    }
                    
                    // Don't add to new mappings.
                }
            }
            
            // Go through ERPLY images. Currently mapped images have been removed in previous loop so we only have
            // images that have been added since last sync.
            foreach($erplyImagesAry as $erplyImage) {
                // Add to Presta
                $prestaImage = self::createPrestaImage($prestaProduct, $erplyImage);
                
                // Add to new mappings array
                if($prestaImage !== false) {
                    $newMappingsAry[$prestaImage->id] = $erplyImage['pictureID'];
                }
            }
            
            // If cover has been removed
            if($coverRemoved === true && count($newMappingsAry) > 0) {
                // Set first mapping as a new cover.
                $id               = array_shift(array_keys($newMappingsAry));
                $prestaCoverImage = new Image($id);
                
                if((bool) $prestaCoverImage->cover !== true) {
                    $prestaCoverImage->cover = true;
                    $prestaCoverImage->update();
                }
            }
            
            // Save new mappings.
            $mapping->setInfo('images', $newMappingsAry);
            $mapping->update();
        }
        
        // Update search indexes
        Search::indexation(false);
        
        return array(
            $prestaProduct,
            $mapping
        );
    }
    
    /**
     * Create Presta Product based on ERPLY data.
     * 
     * @param array $erplyProduct
     * @return Product
     */
    private static function createPrestaProduct($erplyProduct)
    {
        // Only create if product visible in webshop.
        if((int) $erplyProduct['displayedInWebshop'] != 1) {
            return false;
        }
        
        ErplyFunctions::log('Creating Presta product. Code: ' . $erplyProduct['code']);
        
        $localeId = ErplyFunctions::getPrestaLocaleId();
        
        // New Presta Product
        $prestaProduct = new Product();
        
        // If product has set to hidden then dont import.
        if($erplyProduct['displayedInWebshop'] == 0) {
            return false;
        }
        
        // Name must be set
        if(empty($erplyProduct['name'])) {
            return false;
        }
        
        // Name. Name must exist in all languages, other multilingual values are not required
        $name                = self::prestaSafeName($erplyProduct['name'], 128);
        $prestaProduct->name = self::createMultiLangField($name);
        
        // Is active
        $prestaProduct->active = (int) $erplyProduct['displayedInWebshop'];
        
        // Indexed
        $prestaProduct->indexed = 1;
        
        // Reduction times
        $reductionTime                 = date('Y-m-d H:i:s', $erplyProduct['added']);
        $prestaProduct->reduction_from = $reductionTime;
        $prestaProduct->reduction_to   = $reductionTime;
        
        // Code. Presta code cannot be more than 32 characters logn.
        $prestaProduct->reference = substr($erplyProduct['code'], 0, 32);
        
        // EAN
        if(!empty($erplyProduct['code2']) && Validate::isEan13($erplyProduct['code2'])) {
            $prestaProduct->ean13 = $erplyProduct['code2'];
        }
        
        // Description
        $shortDescription                            = !empty($erplyProduct['description']) ? $erplyProduct['description'] : '';
        $shortDescription                            = mb_substr($shortDescription, 0, 400);
        $longDescription                             = !empty($erplyProduct['longdesc']) ? $erplyProduct['longdesc'] : '';
        $prestaProduct->description_short[$localeId] = $shortDescription;
        $prestaProduct->description[$localeId]       = $longDescription;
        
        // Categories
        $categoryMapping = self::getCategoryMapping('erply_id', $erplyProduct['groupID']);
        if(!is_null($categoryMapping)) {
            $prestaProduct->id_category         = $categoryMapping->getPrestaId();
            $prestaProduct->id_category_default = $categoryMapping->getPrestaId();
        }
        
        // Link rewrite
        $prestaProduct->link_rewrite[$localeId] = Tools::link_rewrite($prestaProduct->name[$localeId]);
        
        // Vatrate
        $prestaProduct->id_tax_rules_group = intval(self::getTaxIdByRate(floatval($erplyProduct['vatrate'])));
        $prestaProduct->id_tax             = intval(self::getTaxIdByRate(floatval($erplyProduct['vatrate'])));
        
        // Prices
        $prestaProduct->price  = $erplyProduct['price'];
        $prestaProduct->weight = !empty($erplyProduct['netWeight']) ? $erplyProduct['netWeight'] : 0;
        
        // Manufacturer
        if(!empty($erplyProduct['brandName'])) {
            $brandName                        = self::prestaSafeName($erplyProduct['brandName']);
            $prestaManuf                      = self::getOrAddPrestaManufacturer($brandName);
            $prestaProduct->id_manufacturer   = $prestaManuf['id_manufacturer'];
            $prestaProduct->manufacturer_name = $prestaManuf['name'];
        }
        
        // Supplier
        if(!empty($erplyProduct['supplierName'])) {
            $supplierName                 = self::prestaSafeName($erplyProduct['supplierName']);
            $prestaSupplier               = self::getOrAddPrestaSupplier($supplierName);
            $prestaProduct->id_supplier   = $prestaSupplier['id_supplier'];
            $prestaProduct->supplier_name = $prestaSupplier['name'];
        }
        
        // Qty. Combine all warehouses. 
        $erplyQuantity = self::getErplyQuantity($erplyProduct);
        if($erplyQuantity < 0) {
            $erplyQuantity = 0;
        }
        // Decimal quantities are not allowed.
        $erplyQuantity = round((float) $erplyQuantity, 0);
        // Quantity can have max 6 digits.
        if($erplyQuantity > 999999) {
            $erplyQuantity = 999999;
        }
        
        // All
        if($prestaProduct->add()) {
            if(isset($prestaProduct->id_category)) {
                $prestaProduct->updateCategories(array_map('intval', array(
                    $prestaProduct->id_category
                )));
            }
        } else {
            ErplyFunctions::log('Error: Failed to create product "' . $erplyProduct['code'] . '" in Prestashop');
            return false;
        }
        
        // Create mapping
        $mapping              = new ErplyMapping();
        $mapping->object_type = 'Product';
        $mapping->local_id    = $prestaProduct->id;
        $mapping->erply_id    = $erplyProduct['productID'];
        
        // Set quantity
        self::setPrestaProductQuantity($prestaProduct, $erplyQuantity);
        
        /*
         * Images
         */
        
        if(!empty($erplyProduct['images']) && is_array($erplyProduct['images'])) {
            $imagesMapping = array();
            foreach($erplyProduct['images'] as $erplyImage) {
                // Create image
                $prestaImage = self::createPrestaImage($prestaProduct, $erplyImage);
                
                // Add to mappings
                if($prestaImage !== false) {
                    $imagesMapping[$prestaImage->id] = $erplyImage['pictureID'];
                }
            }
            
            // Add images mappings
            $mapping->setInfo('images', $imagesMapping);
        }
        
        // Save mapping to DB
        $mapping->add();
        
        // Create search indexes
        Search::indexation(false);
        
        return array(
            $prestaProduct,
            $mapping
        );
    }
    
    /**
     * @param Product $prestaProduct
     * @param array $erplyImage
     * @return Image
     */
    private static function createPrestaImage($prestaProduct, $erplyImage)
    {
        ErplyFunctions::log('Creating Preata Product Image. Product code: ' . $prestaProduct->reference . ', Image url: ' . $erplyImage['largeURL']);
        
        $prestaLocaleId = ErplyFunctions::getPrestaLocaleId();
        $pos            = Image::getHighestPosition($prestaProduct->id) + 1;
        
        // Create image
        $prestaImage             = new Image();
        $prestaImage->id_product = intval($prestaProduct->id);
        $prestaImage->position   = $pos;
        $prestaImage->cover      = ($pos > 1) ? false : true;
        
        // Legend
        $legend = self::prestaSafeName($erplyImage['name']);
        if(empty($legend))
            $legend = $prestaProduct->name[$prestaLocaleId];
        $prestaImage->legend = self::createMultiLangField($legend);
        
        if($prestaImage->add()) {
            // Copy image from ERPLY to local server.
            self::copyImg($prestaProduct->id, $prestaImage->id, $erplyImage['largeURL']);
            
            return $prestaImage;
        } else {
            return false;
        }
    }
    
    private static function updateErplyParentProduct($prestaProductObj, $mappingObj = null)
    {
        $output = '';
        try {
            if(!is_null($mappingObj)) {
                ErplyFunctions::log('Updating Parent Product ' . $prestaProductObj->reference . ' in ERPLY.');
            } else {
                ErplyFunctions::log('Creating Parent Product ' . $prestaProductObj->reference . ' in ERPLY.');
            }
            
            // Check
            // Get Presta Product Attribute Group ids
            $prestaLocaleId    = ErplyFunctions::getPrestaLocaleId();
            $attributeGroups   = $prestaProductObj->getAttributesGroups($prestaLocaleId);
            $attributeGroupIds = array();
            foreach($attributeGroups as $attrGrp) {
                $attrGrpId = $attrGrp['id_attribute_group'];
                if(FALSE === array_search($attrGrpId, $attributeGroupIds)) {
                    $attributeGroupIds[] = $attrGrpId;
                    ErplyFunctions::log('Attribute Group id: ' . $attrGrpId);
                }
            }
            
            // Get Attribute Group Mappings AND check Mappings
            $attributeErplyMappings = array();
            foreach($attributeGroupIds as $id) {
                $attrMapping = ErplyMapping::getMapping('ParentAttribute', 'local_id', $id);
                if(!$attrMapping) {
                    throw new Erply_Exception('In Mapping: Attribute Group id:' . $id . ' not found for Parent Product id:' . $prestaProductObj->id);
                }
                
                $attributeErplyMappings[] = $attrMapping;
                ErplyFunctions::log('Found mapping for Attribute Group id: ' . $id);
            }
            
            // Get Erply Matrix Dimensions
            $api                = ErplyFunctions::getErplyApi();
            $erplyMatrixRecords = $api->getMatrixDimensions();
            $erplyMatrixIds     = array();
            foreach($erplyMatrixRecords as $erplyMatrix) {
                ErplyFunctions::log(__FUNCTION__ . ' recv Erply Matrix dimensionID: ' . $erplyMatrix['dimensionID']);
                $erplyMatrixIds[] = $erplyMatrix['dimensionID'];
            }
            
            // Check if Erply has the needed Matrix Dimensions
            foreach($attributeErplyMappings as $attrMapping) {
                if(FALSE === array_search($attrMapping->erply_id, $erplyMatrixIds)) {
                    throw new Erply_Exception('In Erply: Attribute Group id: ' . $attrMapping->local_id . ' not found');
                }
            }
            // Check end
            
            // Init
            $erplyProductAry = array();
            
            // Matrix Dimensions
            $it = 0;
            foreach($attributeErplyMappings as $attrMapping) {
                $erplyProductAry['dimensionID' . $it++] = $attrMapping->erply_id;
            }
            
            // Group
            $categoryMappingObj = self::getCategoryMapping('local_id', $prestaProductObj->id_category_default);
            if(!is_null($categoryMappingObj)) {
                // Group mapping found
                $erplyProductAry['groupID'] = $categoryMappingObj->getErplyId();
            } else {
                // Add to default group
                $defaultCategoryAry = self::getErplyDefaultCategory();
                if(is_array($defaultCategoryAry) && isset($defaultCategoryAry['productGroupID'])) {
                    $erplyProductAry['groupID'] = $defaultCategoryAry['productGroupID'];
                } else {
                    throw new Erply_Exception('ERPLY Category not found for Parent product ' . $prestaProductObj->id);
                }
            }
            
            // Type
            $erplyProductAry['type'] = 'MATRIX';
            
            // Code
            $erplyProductAry['code'] = $prestaProductObj->reference;
            
            // EAN
            if(!empty($prestaProductObj->ean13)) {
                $erplyProductAry['code2'] = $prestaProductObj->ean13;
            }
            
            // Active status
            if(!$prestaProductObj->active) {
                $erplyProductAry['status'] = 'NOT_FOR_SALE';
            } else {
                $erplyProductAry['status'] = 'ACTIVE';
            }
            
            // Price
            // $erplyProductAry['netPrice'] = $prestaProductObj->price;
            $erplyProductAry['netPrice'] = $prestaProductObj->getPrice(false);
            
            // Short description
            if(!empty($prestaProductObj->description_short)) {
                $erplyProductAry['description'] = $prestaProductObj->description_short;
            }
            
            // Long description
            if(!empty($prestaProductObj->description)) {
                $erplyProductAry['longdesc'] = $prestaProductObj->description;
            }
            
            // Tax rate
            $erplyVatrateAry              = self::getErplyVatrateByRate($prestaProductObj->tax_rate);
            $erplyProductAry['vatrateID'] = !is_null($erplyVatrateAry) ? $erplyVatrateAry['id'] : null;
            
            // Product already mapped
            if(!is_null($mappingObj)) {
                // Product id
                $erplyProductAry['productID'] = $mappingObj->getErplyId();
                
                // Name. Use language specific name because product already exists.
                // $nameField                   = 'name' . strtoupper(ErplyFunctions::getErplyLocaleCode());
                $erplyProductAry['name'] = $prestaProductObj->name;
            } else {
                // New ERPLY product
                
                // Use name parameter instead of language specific because presta language may not be
                // the same used in ERPLY and then you would get error or a product with empty name in ERPLY.
                $erplyProductAry['name'] = $prestaProductObj->name;
            }
            
            // Save product through API
            $newId                        = ErplyFunctions::getErplyApi()->saveProduct($erplyProductAry);
            $erplyProductAry['productID'] = $newId;
            
            // Create mapping.
            if(is_null($mappingObj)) {
                $mappingObj              = new ErplyMapping();
                $mappingObj->object_type = 'ParentProduct';
                $mappingObj->local_id    = $prestaProductObj->id;
                $mappingObj->erply_id    = $erplyProductAry['productID'];
                $mappingObj->info        = array();
                $mappingObj->add();
            }
            
            return array(
                $erplyProductAry,
                $mappingObj
            );
        }
        catch(Erply_Exception $e) {
            $output .= Utils::getErrorHtml($e);
            $e->setData('output', $output);
            throw $e;
        }
    }
    
    /**
     * Update ERPLY product group.
     * 
     * @param Product $prestaProductObj
     * @param ErplyMapping $mappingObj
     * @return array - ERPLY product, mappingObj
     */
    private static function updateErplyProduct($prestaProductObj, $productAttributeCombinations, $mappingObj = null)
    {
        $output = '';
        try {
            $idProductAttribute = $productAttributeCombinations[0]['id_product_attribute'];
            if(!is_null($mappingObj)) {
                ErplyFunctions::log('Updating Product ' . $prestaProductObj->reference . ' id_product_attribute: ' . $idProductAttribute . ' in ERPLY.');
            } else {
                ErplyFunctions::log('Creating Product ' . $prestaProductObj->reference . ' id_product_attribute: ' . $idProductAttribute . ' in ERPLY.');
            }
            
            // Init
            $erplyProductAry = array();
            
            // Group
            $categoryMappingObj = self::getCategoryMapping('local_id', $prestaProductObj->id_category_default);
            if(!is_null($categoryMappingObj)) {
                // Group mapping found
                $erplyProductAry['groupID'] = $categoryMappingObj->getErplyId();
            } else {
                // Add to default group
                $defaultCategoryAry = self::getErplyDefaultCategory();
                if(is_array($defaultCategoryAry) && isset($defaultCategoryAry['productGroupID'])) {
                    $erplyProductAry['groupID'] = $defaultCategoryAry['productGroupID'];
                } else {
                    throw new Erply_Exception('ERPLY Category not found for product ' . $prestaProductObj->id);
                }
            }
            
            // Active status
            // if(!$prestaProductObj->active) {
            //     $erplyProductAry['status'] = 'NOT_FOR_SALE';
            // }
            
            // Create Matrix varaition (Erply allows maximum 3 varaitions per product, so we take the first 3)
            // !!! DUPLICATION
            // Get Presta Product Attribute Group ids
            $prestaLocaleId    = ErplyFunctions::getPrestaLocaleId();
            $attributeGroups   = $prestaProductObj->getAttributesGroups($prestaLocaleId);
            $attributeGroupIds = array();
            foreach($attributeGroups as $attrGrp) {
                $attrGrpId = $attrGrp['id_attribute_group'];
                if(FALSE === array_search($attrGrpId, $attributeGroupIds)) {
                    $attributeGroupIds[] = $attrGrpId;
                    ErplyFunctions::log('Attribute Group id: ' . $attrGrpId);
                }
            }
            
            // Get Attribute Group Mappings AND check Mappings
            $attributeErplyMappings = array();
            foreach($attributeGroupIds as $id) {
                $attrMapping = ErplyMapping::getMapping('ParentAttribute', 'local_id', $id);
                if($attrMapping) {
                    $attributeErplyMappings[] = $attrMapping;
                    ErplyFunctions::log('Found mapping for Attribute Group id: ' . $id);
                } else {
                    throw new Erply_Exception('In Mapping: Attribute Group id:' . $id . ' not found for Product id:' . $prestaProductObj->id);
                }
            }
            
            // Get Erply Matrix Dimensions
            $api                = ErplyFunctions::getErplyApi();
            $erplyMatrixRecords = $api->getMatrixDimensions();
            // !!! DUPLICATION END
            
            $codeAppend = '';
            for($it = 0; $it < 3 && $it < count($productAttributeCombinations); $it++) {
                $erplyDimValueID = 0;
                $currAttrComb    = 0;
                $currMapping     = $attributeErplyMappings[$it];
                foreach($productAttributeCombinations as $attrComb) {
                    if($attrComb['id_attribute_group'] == $currMapping->local_id) {
                        $currAttrComb = $attrComb;
                    }
                }
                
                if(!$currAttrComb) {
                    throw new Erply_Exception('Error matching given Attribute Combinations for Presta id: ' . $currMapping->local_id . ' Product id:' . $prestaProductObj->id . ' id_product_attribute: ' . $idProductAttribute);
                }
                
                foreach($erplyMatrixRecords as $erplyMatrix) {
                    if($erplyMatrix['dimensionID'] == $currMapping->erply_id) {
                        foreach($erplyMatrix['variations'] as $erplyVariation) {
                            if($erplyVariation['name'] == $currAttrComb['attribute_name']) {
                                $erplyDimValueID = $erplyVariation['variationID'];
                            }
                        }
                    }
                }
                
                if(!$erplyDimValueID) {
                    throw new Erply_Exception('Error finding Erply Matrix VariationID for id_attribute_group: ' . $currAttrComb['id_attribute_group'] . ' Product id:' . $prestaProductObj->id . ' id_product_attribute: ' . $idProductAttribute);
                }
                
                $erplyProductAry['dimValueID' . $it] = $erplyDimValueID;
                $codeAppend .= $currAttrComb['attribute_name'];
            }
            
            // Code
            $erplyProductAry['code'] = $productAttributeCombinations[0]['reference'] . '_' . $codeAppend;
            
            // EAN
            if(!empty($prestaProductObj->ean13)) {
                $erplyProductAry['code2'] = $prestaProductObj->ean13;
            }
            
            // Price
            // $erplyProductAry['netPrice'] = (int)$prestaProductObj->price + (int)$productAttributeCombinations[0]['price'];
            $erplyProductAry['netPrice'] = $prestaProductObj->getPrice(false, $idProductAttribute);
            
            // Short description
            if(!empty($prestaProductObj->description_short)) {
                $erplyProductAry['description'] = $prestaProductObj->description_short;
            }
            
            // Long description
            if(!empty($prestaProductObj->description)) {
                $erplyProductAry['longdesc'] = $prestaProductObj->description;
            }
            
            // Tax rate
            $erplyVatrateAry              = self::getErplyVatrateByRate($prestaProductObj->tax_rate);
            $erplyProductAry['vatrateID'] = !is_null($erplyVatrateAry) ? $erplyVatrateAry['id'] : null;
            
            // Product already mapped
            if(!is_null($mappingObj)) {
                // Product id
                $erplyProductAry['productID'] = $mappingObj->getErplyId();
                ErplyFunctions::log('Product combination already mapped by erply id: ' . $erplyProductAry['productID']);
                
                // Name. Use language specific name because product already exists.
                // $nameField = 'name'.strtoupper(ErplyFunctions::getErplyLocaleCode());
                $erplyProductAry['name'] = $prestaProductObj->name;
            } else {
                // New ERPLY product
                $erplyProductAry['parentProductID'] = ErplyMapping::getMapping('ParentProduct', 'local_id', $prestaProductObj->id)->erply_id;
                ErplyFunctions::log('New Product Combination. Matrix Product Erply id: ' . $erplyProductAry['parentProductID']);
                
                // Use name parameter instead of language specific because presta language may not be
                // the same used in ERPLY and then you would get error or a product with empty name in ERPLY.
                $erplyProductAry['name'] = $prestaProductObj->name;
            }
            
            // Save product through API
            $newId                        = ErplyFunctions::getErplyApi()->saveProduct($erplyProductAry);
            $erplyProductAry['productID'] = $newId;
            
            // Create mapping.
            if(is_null($mappingObj)) {
                $mappingObj              = new ErplyMapping();
                $mappingObj->object_type = 'Product';
                $mappingObj->local_id    = $idProductAttribute;
                $mappingObj->erply_id    = $erplyProductAry['productID'];
                $mappingObj->setInfo(array(
                    'parent' => $prestaProductObj->id,
                    'quantity' => $productAttributeCombinations[0]['quantity'],
                    'date_upd' => strtotime($prestaProductObj->date_upd)
                ));
                $mappingObj->add();
            } else {
                $info             = $mappingObj->getInfo();
                $info['quantity'] = $productAttributeCombinations[0]['quantity'];
                $info['date_upd'] = strtotime($prestaProductObj->date_upd);
                $mappingObj->setInfo($info);
            }
            
            /**
             * Update images.
             */
            
            // $prestaImagesAry = Image::getImages( ErplyFunctions::getPrestaLocaleId(), $prestaProductObj->id );
            // $currentMappingsAry = $mappingObj->getInfo('images');
            // if(!is_array($currentMappingsAry)) $currentMappingsAry = array();
            // 
            // // Update only if current mappings exist or Presta images exist.
            // if( count($prestaImagesAry)	|| count($currentMappingsAry) ) {
            // 	// We are only interested in mappings that no longer have images (removed from admin panel)
            // 	// and images that are not mapped (added from admin panel).
            // 
            // 	$newMappingsAry = array();
            // 
            // 	// So we first create a tmp array of presta product images.
            // 	$prestaImagesIdsAry = array();
            // 	foreach($prestaImagesAry as $i=>$prestaImage) {
            // 		$prestaImagesIdsAry[ $prestaImage['id_image'] ] = $i;
            // 	}
            // 
            // 	// Go through images that exist both in mappings and in Presta. Leave these untouched.
            // 	foreach(array_intersect_key($currentMappingsAry, $prestaImagesIdsAry) as $prestaImageId=>$erplyImageId) {
            // 		$newMappingsAry[ $prestaImageId ] = $erplyImageId;
            // 	}
            // 
            // 	// Go thourgh images that only exist in mappings and remove these from ERPLY and mappings.
            // 	// These are images that have been deleted from admin panel.
            // 	foreach(array_diff_key($currentMappingsAry, $prestaImagesIdsAry) as $prestaImageId=>$erplyImageId) {
            // 		// @todo ERPLY API does not support image removing currently.
            // 	}
            // 
            // 	// Go through images that only exist in Presta and add these to ERPLY.
            // 	foreach(array_diff_key($prestaImagesIdsAry, $currentMappingsAry) as $prestaImageId=>$i) {
            // 		$prestaImage = $prestaImagesAry[ $i ];
            // 		$erplyImageAry = self::createErplyProductImage($prestaProductObj, $prestaImage, $mappingObj);
            // 		if($erplyImageAry) {
            // 			$newMappingsAry[ $prestaImageId ] = $erplyImageAry['pictureID'];
            // 		}
            // 	}
            // 
            // 	// Save new mappings.
            // 	$mappingObj->setInfo('images', $newMappingsAry);
            // 	$mappingObj->update();
            // }
            
            return array(
                $erplyProductAry,
                $mappingObj
            );
        }
        catch(Erply_Exception $e) {
            $output .= Utils::getErrorHtml($e);
            $e->setData('output', $output);
            throw $e;
        }
    }
    
    /**
     * Creates new ERPLY product picture based on Presta product image.
     * 
     * @param Product $prestaProductObj
     * @param array $prestaImageAry
     * @param ErplyMapping $mappingObj
     * @return array $erplyImageAry
     */
    private static function createErplyProductImage($prestaProductObj, $prestaImageAry, $mappingObj)
    {
        ErplyFunctions::log('Creating ERPLY Product image. Product code: ' . $prestaProductObj->reference . ', Presta image ID: ' . $prestaImageAry['id_image']);
        
        $img             = new Image($prestaImageAry['id_image']);
        $prestaImagePath = $_SERVER['DOCUMENT_ROOT'] . _THEME_PROD_DIR_ . $img->getExistingImgPath() . '.jpg';
        if(file_exists($prestaImagePath) && is_file($prestaImagePath)) {
            // Init
            $erplyImageAry = array();
            
            // Product
            $erplyImageAry['productID'] = $mappingObj->getErplyId();
            
            // Filename
            $pathinfo                  = pathinfo($prestaImagePath);
            $erplyImageAry['filename'] = basename($prestaImagePath);
            
            // Image content
            $erplyImageAry['picture'] = base64_encode(file_get_contents($prestaImagePath));
            
            // Save through ERPLY API.
            $newId = ErplyFunctions::getErplyApi()->saveProductPicture($erplyImageAry);
            if($newId) {
                $erplyImageAry['pictureID'] = $newId;
                return $erplyImageAry;
            }
        }
        
        return false;
    }
    
    public static function deleteParentProduct($productId, $api = false)
    {
        $output = '';
        if(!$api) {
            // Verify connection with erply
            $api = ErplyFunctions::getErplyApi();
            $api->VerifyUser();
            // Connection with erply OK
        }        
        
        $parentProductMapping = ErplyMapping::getMapping('ParentProduct', 'local_id', $productId);
        if($parentProductMapping) {
            try {
                $api->deleteProduct(array('productID' => $parentProductMapping->erply_id));
                $output .= self::logAndReturn('Product with id: ' . $productId . ' delete from Erply successful.');
            }
            catch(Erply_Exception $e) {
                if($e->getData('code') == 1002) {
                    $output .= self::logAndReturn('Product id: ' . $productId . ' delete failed. Maximum requests reached. No changes', 'warn');
                    $e-setData('output', $output);
                    throw $e;
                } else if($e->getData('code') == 1063) {
                    $output .= self::logAndReturn('Product id: ' . $productId . ' unable to delete from Erply. Archive product in Erply', 'warn');
                    
                    // UNTESTED
                    try {
                        $erplyProductAry = array();
                        $erplyProductAry['productID'] = $parentProductMapping->erply_id;
                        $erplyProductAry['status'] = 'ARCHIVED';
                        $api->saveProduct($erplyProductAry);
                    }
                    catch(Erply_Exception $e) {
                        $output .= self::logAndReturn('Product id: ' . $productId . ' unable to archive in Erply. No changes', 'warn');
                        $output .= self::logAndReturn('Error code: ' . $e->getData('code') . ' message: ' . $e->getData('message'), 'warn');
                        $e-setData('output', $output);
                        throw $e;
                    }
                    
                } else {
                    $output .= self::logAndReturn('Error code: ' . $e->getData('code') . ' message: ' . $e->getData('message') . '. No changes', 'err');
                    $e-setData('output', $output);
                    throw $e;
                }
            }
            
            // clear local mapping
            if(!ErplyMapping::deleteParentAndChildrenMappingsByPrestaId($productId, 'Product')) {
                $output .= self::logAndReturn('Delete Product mappings by id failed', 'warn');
            }
        } else {
            throw new Erply_Exception(ErplyFunctions::log('Could not get ParentProduct mapping by Prestashop Product Id: '.$productId));
        }
        return $output;
    }
    
    // Similar to deleteParentProduct. TODO: combine
    public function deleteProduct($combinationId, $api = false)
    {
        $output = '';
        if(!$api) {
            // Verify connection with erply
            $api = ErplyFunctions::getErplyApi();
            $api->VerifyUser();
            // Connection with erply OK
        }  
        
        $productMapping = ErplyMapping::getMapping('Product', 'local_id', $combinationId);
        if($productMapping) {
            try {
                $api->deleteProduct(array('productID' => $productMapping->erply_id));
                $output .= self::logAndReturn('Product with id: ' . $combinationId . ' delete from Erply successful.');
                $productMapping->delete();
            }
            catch(Erply_Exception $e) {
                if($e->getData('code') == 1002) {
                    $output .= self::logAndReturn('Product Combination id: ' . $combinationId . ' delete failed. Maximum requests reached. No changes', 'warn');
                    $e-setData('output', $output);
                    throw $e;
                } else if($e->getData('code') == 1063) {
                    $output .= self::logAndReturn('Product Combination id: ' . $combinationId . ' unable to delete from Erply. Archive product in Erply', 'warn');
                    
                    // UNTESTED
                    try {
                        $erplyProductAry = array();
                        $erplyProductAry['productID'] = $productMapping->erply_id;
                        $erplyProductAry['status'] = 'ARCHIVED';
                        $api->saveProduct($erplyProductAry);
                    }
                    catch(Erply_Exception $e) {
                        $output .= self::logAndReturn('Product Combination id: ' . $combinationId . ' unable to archive in Erply. No changes', 'warn');
                        $output .= self::logAndReturn('Error code: ' . $e->getData('code') . ' message: ' . $e->getData('message'), 'warn');
                        $e-setData('output', $output);
                        throw $e;
                    }
                    
                } else {
                    $output .= self::logAndReturn('Error code: ' . $e->getData('code') . ' message: ' . $e->getData('message') . '. No changes', 'err');
                    $e-setData('output', $output);
                    throw $e;
                }
            }
        } else {
            throw new Erply_Exception(ErplyFunctions::log('Could not get Product mapping by Prestashop Product Combination Id: ' . $combinationId));
        }
        return $output;  
    }
    
    /**
     * Get array of Presta Products that have changed since last sync.
     * 
     * @return array
     */
    private static function getPrestaChangedProductsIds()
    {
        if(is_null(self::$_prestaChangedProductsIds)) {
            // Init
            self::$_prestaChangedProductsIds = array();
            
            $lastSyncTS = ErplyFunctions::getLastSyncTS('PRESTA_PRODUCTS');
            $sql        = '
SELECT p.`id_product`  
FROM `' . _DB_PREFIX_ . 'product` p 
WHERE 
	UNIX_TIMESTAMP(p.`date_add`) > ' . intval($lastSyncTS) . ' 
	OR UNIX_TIMESTAMP(p.`date_upd`) > ' . intval($lastSyncTS) . '
ORDER BY p.`date_upd` ASC';
            
            $productsAry = Db::getInstance()->ExecuteS($sql);
            if(is_array($productsAry)) {
                foreach($productsAry as $productAry) {
                    self::$_prestaChangedProductsIds[] = $productAry['id_product'];
                }
            }
        }
        return self::$_prestaChangedProductsIds;
    }
    
    /**
     * Check if Presta product has changed since last sync.
     * 
     * @param integer $prestaProductId
     * @return bool
     */
    private static function prestaProductHasChanged($prestaProductId)
    {
        $ids = self::getPrestaChangedProductsIds();
        return array_key_exists($prestaProductId, $ids);
    }
    
    /**
     * Check if ERPLY product has changed since last sync.
     * 
     * @param integer $erplyProductId
     * @return bool
     */
    private static function erplyProductHasChanged($erplyProductId)
    {
        if(is_array(self::$_erplyChangedProductsIds)) {
            return array_key_exists($erplyProductId, self::$_erplyChangedProductsIds);
        } else {
            return false;
        }
    }
    
    /**
     * Get Presta manufacturer id by ERPLY brand name.
     * 
     * @param string $erplyBrandName
     * @return array
     */
    private static function getOrAddPrestaManufacturer($erplyBrandName)
    {
        // Get Manufacturer ID by name.
        $prestaManufId = Manufacturer::getIdByName($erplyBrandName);
        
        // If manuf not found and can be created
        if($prestaManufId === false) {
            ErplyFunctions::log('Creating Presta Manufacturer. Name: ' . $erplyBrandName);
            $newManufObj         = new Manufacturer();
            $newManufObj->name   = $erplyBrandName;
            $newManufObj->active = 1;
            if($newManufObj->add()) {
                $prestaManufId = $newManufObj->id;
            }
        }
        
        if(!empty($prestaManufId)) {
            return array(
                'id_manufacturer' => $prestaManufId,
                'name' => $erplyBrandName
            );
        } else {
            return null;
        }
        
        // Load existing manufacturers.
        //		if(is_null(self::$_prestaManufAry)) {
        //			self::$_prestaManufAry = Manufacturer::getManufacturers();
        //		}
        
        // Find manuf. by name and return.
        //		if(is_array(self::$_prestaManufAry)) {
        //			foreach(self::$_prestaManufAry as $prestaManuf) {
        //				if($prestaManuf['name'] == $erplyBrandName)
        //				{
        //					return $prestaManuf;
        //				}
        //			}
        //		}
        
        // Create new manuf.
        ErplyFunctions::log('Creating Presta Manufacturer. Name: ' . $erplyBrandName);
        $newManufObj       = new Manufacturer();
        $newManufObj->name = $erplyBrandName;
        if($newManufObj->add()) {
            // Add result to global cache.
            $newManufAry             = array(
                'id_manufacturer' => $newManufObj->id,
                'name' => $newManufObj->name
            );
            self::$_prestaManufAry[] = $newManufAry;
            
            // Return new object id.
            return $newManufAry;
        }
        
        // Nothing to return
        return null;
    }
    
    /**
     * Get PS supplier id by ERPLY supplier name.
     * 
     * @param string $erplySupplierName
     * @return array
     */
    private static function getOrAddPrestaSupplier($erplySupplierName)
    {
        // Load existing manufacturers.
        if(is_null(self::$_prestaSuppliersAry)) {
            self::$_prestaSuppliersAry = Supplier::getSuppliers();
        }
        
        // Find supplier by name and return.
        if(is_array(self::$_prestaSuppliersAry)) {
            foreach(self::$_prestaSuppliersAry as $prestaSupplier) {
                if($prestaSupplier['name'] == $erplySupplierName) {
                    return $prestaSupplier;
                }
            }
        }
        
        // Create new manuf.
        ErplyFunctions::log('Creating Presta Supplier. Name: ' . $erplySupplierName);
        $newSupplierObj         = new Supplier();
        $newSupplierObj->name   = $erplySupplierName;
        $newSupplierObj->active = 1;
        if($newSupplierObj->add()) {
            // Add result to global cache.
            $newSupplierAry              = array(
                'id_supplier' => $newSupplierObj->id,
                'name' => $newSupplierObj->name
            );
            self::$_prestaSuppliersAry[] = $newSupplierAry;
            
            // Return new object id.
            return $newSupplierAry;
        }
        
        // Nothing to return
        return null;
    }
    
    private static function getErplyQuantity($erplyProduct)
    {
        $quantity = 0;
        if($erplyProduct['warehouses']) {
            foreach($erplyProduct['warehouses'] as $warehouse) {
                $quantity += $warehouse['free'];
            }
        }
        return $quantity;
    }
    
    private static function copyImg($id_entity, $id_image = NULL, $url, $entity = 'products')
    {
        $tmpfile         = tempnam(_PS_TMP_IMG_DIR_, 'ps_import');
        $watermark_types = explode(',', Configuration::get('WATERMARK_TYPES'));
        
        switch($entity) {
            default:
            case 'products':
                $image_obj = new Image($id_image);
                $path      = $image_obj->getPathForCreation();
                break;
            case 'categories':
                $path = _PS_CAT_IMG_DIR_ . (int) $id_entity;
                break;
        }
        $url = str_replace(' ', '%20', trim($url));
        
        // Evaluate the memory required to resize the image: if it's too much, you can't resize it.
        if(!ImageManager::checkImageMemoryLimit($url))
            return false;
        
        // 'file_exists' doesn't work on distant file, and getimagesize make the import slower.
        // Just hide the warning, the traitment will be the same.
        $opts    = array(
            'http' => array(
                'method' => "GET",
                'header' => "User-Agent: PrestaShop Connector ver.1.0\r\n"
            )
        );
        $context = stream_context_create($opts);
        if(@copy($url, $tmpfile, $context)) {
            ImageManager::resize($tmpfile, $path . '.jpg');
            $images_types = ImageType::getImagesTypes($entity);
            foreach($images_types as $image_type)
                ImageManager::resize($tmpfile, $path . '-' . stripslashes($image_type['name']) . '.jpg', $image_type['width'], $image_type['height']);
            
            if(in_array($image_type['id_image_type'], $watermark_types))
                Hook::exec('actionWatermark', array(
                    'id_image' => $id_image,
                    'id_product' => $id_entity
                ));
        } else {
            unlink($tmpfile);
            return false;
        }
        unlink($tmpfile);
        return true;
    }
    
    private static function getTaxIdByRate($rate)
    {
        
        $taxes  = Tax::getTaxes();
        $id_tax = (int) Configuration::get('ERPLY_DEFAULT_TAXGROUP');
        
        foreach($taxes as $tax) {
            if(round(floatval($tax['rate']), 2) == round(floatval($rate), 2)) {
                $id_tax = $tax['id_tax'];
                break;
            }
        }
        return $id_tax;
    }
    
    public static function setPrestaProductQuantity($product, $quantity)
    {
        $attributes           = Product::getProductAttributesIds($product->id);
        $id_product_attribute = 0;
        
        $product->deleteDefaultAttributes();
        
        if(!empty($attributes) && $attributes[0]['id_product_attribute']) {
            $id_product_attribute = $attributes[0]['id_product_attribute'];
        }
        
        if(!Shop::isFeatureActive()) {
            $id_shop_list[] = 1;
        } elseif($shops = Shop::getContextListShopID()) {
            foreach($shops as $shop) {
                if(!is_numeric($shop))
                    $id_shop_list[] = Shop::getIdByName($shop);
                else
                    $id_shop_list[] = $shop;
            }
        }
        
        if(!$id_product_attribute) {
            $id_product_attribute = $product->addCombinationEntity((float) 0, 0, (float) $product->weight, 0, (float) 0, (int) $quantity, null, null, 0, strval($product->ean13), null, 0, null, 1, $id_shop_list);
        }
        
        $product->setDefaultAttribute($id_product_attribute);
        
        StockAvailable::setQuantity($product->id, $id_product_attribute, $quantity);
        
    }
    
}

?>