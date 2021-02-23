<?php

require_once('/home/krasiva/public_html/wp-load.php');

class WOO_I1C_Application
{
    function __construct($autoStart = false)
    {
        if ($autoStart) {
            //		$this->run();
        }
    }

    public function run()
    {
        add_action('admin_menu', array($this, 'pluginskeleton_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        $this->setup_ajax_handlers();
    }

    public function get_all_terms()
    {
        $terms = get_terms(['taxonomy' => 'product_cat', 'fields' => 'id=>slug']);
        if (!empty($terms)) {
            foreach ($terms as $term) {
                $this->logger_data(['категория' => $term], 'update_term_product');
                $this->update_term($term);
            }
        }
    }

    public function pluginskeleton_menu()
    {
        add_menu_page('Woocommerce import 1c', 'Woocommerce import 1c', 'manage_options', 'woo_page_1c', array(
            $this,
            'woo_page_1c'
        ));
    }

    public function woo_page_1c()
    {
        require 'admin/woo_page_1c.php';
    }

    /**
     * add script
     */
    public function enqueue_scripts()
    {
        wp_enqueue_script(
            'ajax-script-xml',
            plugin_dir_url(__FILE__) . 'assets/js/script.js',
            array('')
        );
    }

    public function setup_ajax_handlers()
    {
        add_action(
            'wp_ajax_upload_xml',
            array($this, 'upload_xml')
        );
        add_action(
            'wp_ajax_create_xml',
            array($this, 'create_xml')
        );
    }

    /**
     * @throws WC_Data_Exception
     */
    public function upload_xml()
    {

        $uploaddir = wp_upload_dir()['basedir'];
        //	$data = $done_files ? array('files' => $done_files) : array('error' => 'Ошибка загрузки файлов.');
        $reader = new XMLReader();
        $name = '14_ProkatD';
        $reader->open($uploaddir . '/woo_product_import/' . $name . '.xml');
        $reader->read();
        //	echo $uploaddir . '/woo_product_import/' . $name . '.xml';
        if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'VigrNom') {
            $doc = new DOMDocument('1.0', 'UTF-8');
            $xml = simplexml_import_dom($doc->importNode($reader->expand(), true));
            foreach ($xml as $product) {
                $this->create_product(($product), '', 1, count($xml));
            }
        }
        die;
    }

    public function update_image_wp()
    {

        
        $attachments = get_posts(array('post_type' => 'attachment', 'numberposts' => -1));
        foreach ($attachments as $attach) {
            $parent_id = $attach->post_parent;
            $image = $attach->ID;
            if (!empty($parent_id) && empty(wp_get_attachment_metadata($image))) {
                wp_generate_attachment_metadata($image, get_attached_file($image));
            }
        }

        
    }

    /**
     * @param $array
     *
     * @throws WC_Data_Exception
     */
    public function update_image($array)
    {
        foreach ($array as $value) {
            if (isset($value->ID)) {
                $image_id = $value->ID;
                wp_delete_attachment($image_id, true);
            }
        }
    }


    private function create_Attr($array)
    {
        $attribute = new WC_Product_Attribute();
        $attribute->set_name('options'); //attribute name
        $attribute->set_options($array); // attribute value
        $attribute->set_visible(1); //attribute visiblity
        $attribute->set_variation(1); //to use this attribute as varint or not
        //		$attribute->is_taxonomy(false);
        return $attribute;
    }

    private function create_variable($name, $attribute, $Kod)
    {
        $products = new WC_Product_Variable(); //$wcProductID
        $products->set_name($name);
        $products->set_sku($Kod);
        $products->set_attributes([$attribute]);

        return $products->save();
    }

    public function create_product_demo($array)
    {
        $attr_names = [];
        $images_attr = [];
        $sku_attr = [];
        $name = (string)$array->attributes()->Nazv;
        $Kod = (string)$array->attributes()->Kod;
        $price = str_replace(',', '.', (string)$array->attributes()->Cena);
        $a = 0;
        foreach ($array->Har as $key => $har) {
            $naz = (string)$har->attributes()->Nazv;
            $sku = (string)$har->attributes()->Kod;
            if ((string)$har->attributes()->Nazv === '.') {
                $naz = $name;
            } else {
                $naz = (string)$har->attributes()->Nazv;
            }
            $attr_names[] = $naz;
            $basename1 = (string)$har->FotoH->attributes()->Kart;
            $basename = self::mb_basename($basename1);
            $img_id = $this->uploadMediaDate($basename);
            $images_attr[$a] = $img_id;
            $sku_attr[$a] = $sku;
            $a++;
        }
        $attribute = self::create_Attr($attr_names);
        if (wc_get_product_id_by_sku($Kod) !== 0) {
            $product_id = wc_get_product_id_by_sku($Kod);
        } else {
            $product_id = self::create_variable($name, $attribute, $Kod);
        }
        //	update_post_meta( $product_id, '_product_attributes', $attr_names );
        $slugs = self::add_attribute($attr_names, $product_id);
        $parent = $product_id;
        $product_object = wc_get_product($parent);
        $parent_attrs = $product_object->get_attributes();
        $i = 0;
        foreach ($array->Har as $k => $flow) {
            $slug_name = (string)$flow->attributes()->Nazv;
            $variation_object = new WC_Product_Variation();
            $variation_object->set_parent_id($parent);
            $variation_object->set_attributes(array_fill_keys(array_map('sanitize_title', array_keys($product_object->get_variation_attributes())), ''));
            $variation_id = $variation_object->save();
            if (!is_wp_error($parent)) {
                $variation = new WC_Product_Variation($variation_id);
                if ((string)$flow->attributes()->Nazv === '.') {
                    $attr_name = $name;
                } else {
                    $attr_name = (string)$flow->attributes()->Nazv;
                }
                $errors = $variation->set_props(
                    array(
                        'attribute_options' => $product_object->get_variation_attributes()["Options"][0],
                        'status' => 'publish',
                        'regular_price' => $price,
                        'sku' => $Kod . '_' . $k,
                        'image_id' => $images_attr[$i],
                        'options' => $this->prepare_set_attributes($parent_attrs, 'attribute_', $i),
                    )
                );
                $dat1a = $variation->save();
                update_post_meta($dat1a, 'attribute_options', $attr_names[$i]);
            }
            $i++;
        }
        WC_Product_Variable::sync($parent);

        return $product_id;
    }

    public function prepare_set_attributes($all_attributes, $key_prefix = 'attribute_', $index = null)
    {
        $attributes = array();
        if (!empty($all_attributes)) {
            foreach ($all_attributes as $attribute) {
                if (!empty($attribute)) {

                    $attribute_key = sanitize_title($attribute);
                    if (!is_null($index)) {
                        $value = isset($_POST[$key_prefix . $attribute_key][$index]) ? wp_unslash($_POST[$key_prefix . $attribute_key][$index]) : '';
                    } else {
                        $value = isset($_POST[$key_prefix . $attribute_key]) ? wp_unslash($_POST[$key_prefix . $attribute_key]) : '';
                    }
                    $value = sanitize_title($value);

                    $attributes[$attribute_key] = $value;
                }
            }
        }

        return $attributes;
    }

    public function create_product($array, $url, $offset, $count)
    {
        global $wpdb;
        $variable = [];
        $price = str_replace(',', '.', (string)$array->attributes()->Cena);
        $sku = (string)$array->attributes()->Kod;
        $Nazv1 = (string)$array->attributes()->Nazv;
        //	echo $sku."\n";
        $quantity = (string)$array->attributes()->Ost;
        $category_3 = (string)$array->attributes()->Gr3;
        $category_2 = (string)$array->attributes()->Gr2;
        $category_1 = (string)$array->attributes()->Gr1;
        if ($category_1 == $category_3) {
            $category_3 = '';
        }
        /**
         * function
         */
        if (wc_get_product_id_by_sku($sku)) {
            $product_id = wc_get_product_id_by_sku($sku);

            $product = wc_get_product($product_id);
            if (trim($Nazv1) !== trim($product->get_name())) {
                $this->logger_data(['артикул' => $sku, 'продукт с xml' => $Nazv1, 'продукт на сайте' => $product->get_name()], 'create_product');
            }
            $product->set_name($Nazv1);
            $product->set_regular_price($price);
            $product->save();
            update_field('custom_price', $price, $product_id);
            $type_product = $product->get_type();

            
            if ($type_product == 'variable') {
                $variation_object = new WC_Product_Variable($product_id);
                $children = $variation_object->get_children();
                foreach ($children as $child) {
                    $product_variable = wc_get_product($child);
                    $product_variable->set_regular_price($price);
                    $product_variable->save();
                    //wp_delete_post( $child, true );
                }
            }
    
        } else {
            if (!empty((string)$array->attributes()->Kod) && empty($array->Har)) {
                $post = array(
                    'post_author' => 1,
                    'post_title' => (string)$array->attributes()->Nazv,
                    'post_content' => (string)$array->attributes()->Opis,
                    'post_status' => 'publish',
                    'post_type' => 'product'
                );
                $product_id = wp_insert_post($post);
                echo $product_id . "\n";
                $image = [];
                $product = new WC_Product($product_id);
                $product->set_sku($sku);
                $product->set_regular_price($price);
                $this->add_for($array, $product_id);
                $this->logger_b([$image[0], $product_id]);

                /*save product*/
                $product->save(); // Save the data
                update_field('custom_price', $price, $product_id);
            }

            if (!empty($array->Har)) {

                $product_id = $this->create_product_demo($array);
                //		$this->add_for( $array, $product_id );
            }
            $this->set_category($product_id, $category_3, $category_2, $category_1);
        }
    }

    public function add_for($array, $product_id)
    {
        $image = [];
        $images_id = [];
        if (count($array->Foto) != 0) {
            for ($c = 0; $c < count($array->Foto); $c++) {
                $obrez1 = (string)$array->Foto[$c]->attributes()->Kart;
                $obrez = self::mb_basename($obrez1);
                if ($obrez) {
                    $image[] = $obrez;
                    if ($c != 0) {
                        $images_id[] = $this->uploadMediaDate($obrez);
                    }
                }
            }
            if (!empty($images_id)) {
                $this->add_image($image[0], $product_id);
                unset($image[0]);
                $pr = wc_get_product($product_id);
                $pr->set_gallery_image_ids($images_id);
                $pr->save();
            }
        }
    }

    public function uploadMediaDate($file_name)
    {
        $this->logger_b(['mb_basename' => $file_name, 'basename' => basename($file_name)]);
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $dir = wp_upload_dir();
        $old = $dir['basedir'] . '/woo_product_import/' . $file_name;
        $new = $dir['basedir'] . '/products_images/' . $file_name;
        rename($old, $new);
        $filename_bool = file_exists($new);
        $status_image = $filename_bool ? "update" : "image not fount";
        if ($filename_bool) {

            $filename_dir = $new;
            $parent_post_id = 0;
            $filetype = wp_check_filetype(basename($filename_dir), null);
            $attachment = array(
                'post_mime_type' => $filetype['type'],
                'post_title' => $file_name,
                'post_content' => '',
                'post_status' => 'inherit'
            );
            if (!empty($this->get_attachment_id_by_slug($file_name))) {
                $attach_id = $this->get_attachment_id_by_slug($file_name);
                $attach_data = wp_generate_attachment_metadata($attach_id, $filename_dir);
                wp_update_attachment_metadata($attach_id, $attach_data);
            } else {
                $attach_id = wp_insert_attachment($attachment, $filename_dir, $parent_post_id);
                $attach_data = wp_generate_attachment_metadata($attach_id, $filename_dir);
                wp_update_attachment_metadata($attach_id, $attach_data);
            }

            return $attach_id;
        }

        return false;
    }


    public function get_attachment_id_by_slug($slug)
    {
        $args = array(
            'post_type' => 'attachment',
            'name' => sanitize_title($slug),
            'posts_per_page' => 1,
            'post_status' => 'inherit',
        );
        $_header = get_posts($args);
        $header = $_header ? array_pop($_header) : null;

        return $header ? $header->ID : false;
    }

    public function update_variable($array, $price)
    {
        foreach ($array as $item) {
            update_post_meta($item, '_regular_price', $price);
            update_post_meta($item, '_price', $price);
        }
    }


    /**
     *
     * @param $product_id
     * @param $parent_name_2 3
     * @param $parent_name 2
     * @param $child_name 1
     */
    public function set_category($product_id, $parent_name_2, $parent_name, $child_name)
    {
        global $wpdb;
        if (!empty($parent_name_2)) {

            wp_set_object_terms($product_id, [
                $parent_name_2,
                $parent_name,
                $child_name
            ], 'product_cat', $append = false); // Set up its categories
 
            $category_parent_2 = get_term_by('name', $parent_name_2, 'product_cat');
            $category_parent = get_term_by('name', $parent_name, 'product_cat');
            $category_child = get_term_by('name', $child_name, 'product_cat');
            $category_parent_2_id = $category_parent_2->term_id;
            $category_parent_id = $category_parent->term_id;
            $category_child_id = $category_child->term_id;
            wp_update_term($category_parent_id, 'product_cat', array(
                'name' => $parent_name,
                'parent' => $category_parent_2_id
            ));
            wp_update_term($category_child_id, 'product_cat', array(
                'name' => $child_name,
                'parent' => $category_parent_id
            ));
            wp_update_term($category_parent_2_id, 'product_cat', array(
                'name' => $parent_name_2,
            ));
        } else {
            wp_set_object_terms($product_id, [$parent_name, $child_name], 'product_cat', $append = false); // Set up its categories
            //			wp_set_object_terms( $product_id,$child_name, 'product_cat', $append = true ); // Set up its categories
            $category_parent = get_term_by('name', $parent_name, 'product_cat');
            $category_child = get_term_by('name', $child_name, 'product_cat');
            $category_parent_id = $category_parent->term_id;
            $category_child_id = $category_child->term_id;
            wp_update_term($category_child_id, 'product_cat', array(
                'name' => $child_name,
                'parent' => $category_parent_id
            ));
            wp_update_term($category_parent_id, 'product_cat', array(
                'name' => $parent_name,
            ));
        }
    }

    public function logger_b($data = array())
    {
        $mount = 1;
        $fd = fopen(get_theme_file_path() . "/logfile-$mount.txt", "a+");
        $str = print_r($data, 1);
        fwrite($fd, "\r\n" . $str . "\r\n");
        fclose($fd);
    }

    public function logger_data($data = array(), $name)
    {
        $mount = 1;

        $fd = fopen(wp_upload_dir()['basedir'] . "/logfile-$name-$mount.txt", "a+");
        $str = print_r($data, 1);
        fwrite($fd, "\r\n" . $str . "\r\n");
        fclose($fd);
    }

    public function add_image($filenames, $parent_post_id)
    {


        $img_id = $this->uploadMediaDate($filenames);
        if (is_wp_error($img_id)) {
            $this->logger_b($img_id->get_error_message());
        } else {
            set_post_thumbnail($parent_post_id, $img_id);
        }
    }

    public function add_attribute($attributes, $product_id)
    {
        $comma_separated = implode("|", $attributes);
        $product_attributes['options'] = array(
            'name' => htmlspecialchars(stripslashes('Options')),
            'value' => $comma_separated,
            'position' => 1,
            'is_visible' => 1,
            'is_variation' => 1,
            'is_taxonomy' => 0
        );
        update_post_meta($product_id, '_product_attributes', $product_attributes);

        return $attributes;
    }

    public function mb_basename($path)
    {
        if (preg_match('@^.*[\\\\/]([^\\\\/]+)$@s', $path, $matches)) {
            return $matches[1];
        } else if (preg_match('@^([^\\\\/]+)$@s', $path, $matches)) {
            return $matches[1];
        }

        return '';
    }
    public function getItems()
    {
        $uploaddir = wp_upload_dir()['basedir'];
        $folder = $uploaddir . '/xml/';
        $arrayfiles = scandir($folder);
        foreach ($arrayfiles as $key => $file) {
            if (mime_content_type($folder . $file) == "text/xml") {
             $this->auto_update_wp($file);
          
            }
        }
    }
    public function xml_update()
    {
        $uploaddir = wp_upload_dir()['basedir'];
        $folder = $uploaddir . '/xml/';
        $arrayfiles = scandir($folder);
        $reader = new XMLReader();
        $skus = [];
        foreach ($arrayfiles as $key => $file) {
            if (mime_content_type($folder . $file) == "text/xml") {
                $reader->open($folder . $file);
                $reader->read();
                //	echo $uploaddir . '/woo_product_import/' . $name . '.xml';
                if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'VigrNom') {
                    $doc = new DOMDocument('1.0', 'UTF-8');
                    $xml = simplexml_import_dom($doc->importNode($reader->expand(), true));
                    foreach ($xml as $product) {
                        $skus[$key][] = (string)$product->attributes()->Kod;
                    }
                }
            }
        }
        $result = [];
        array_walk_recursive($skus, function ($item, $key) use (&$result) {
            $result[] = $item;
        });

        $products = wc_get_products(array('status' => 'publish', 'limit' => -1));
        foreach ($products as $prod) {
            $sku1[] = $prod->get_sku();
        }
        $array_diff = array_diff($sku1, $result);
        if (!empty($array_diff)) {
            foreach ($array_diff as $sku) {
                if (!empty($sku)) {
                    $product_id = wc_get_product_id_by_sku($sku);
                    if ($product_id !== 0) {
                        wp_trash_post($product_id);
                    }
                }
            }
        }
    }

    public function auto_update_wp($name)
    {
        $uploaddir = wp_upload_dir()['basedir'];
        //	$data = $done_files ? array('files' => $done_files) : array('error' => 'Ошибка загрузки файлов.');
        $reader = new XMLReader();
        //	$name   = '14_ProkatD';
        $reader->open($uploaddir . '/xml/' . $name);
        $reader->read();
        //	echo $uploaddir . '/woo_product_import/' . $name . '.xml';
        if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'VigrNom') {
            $doc = new DOMDocument('1.0', 'UTF-8');
            $xml = simplexml_import_dom($doc->importNode($reader->expand(), true));
            foreach ($xml as $product) {
            //    sleep(1);
                $this->create_product(($product), '', 1, count($xml));
            }
        }
        var_dump( $name);
        $this->logger_data(['work'], $name);
        echo "finish\n";
        // die;
    }

    public function update_term($term)
    {
        if (!empty($term)) {

            $tax = $term;
            //            $terms = get_terms(['taxonomy' => 'product_cat']);

            $term = get_term_by('slug', $tax, 'product_cat');
            $parent_ids = [];
            $products = new WP_Query([
                'post_type' => 'product', 'posts_per_page' => -1,
                'tax_query' => array(
                    array(
                        'taxonomy' => 'product_cat',
                        'field' => 'slug',
                        'terms' => $tax
                    )
                )
            ]);
            foreach ($products->get_posts() as $post) {
                $product = wc_get_product($post->ID);
                if (!empty($product->get_children())) {
                    $product_id = $post->ID;
                    foreach ($product->get_children() as $child) {
                        $child_product = wc_get_product($child);
                        $child_product->set_category_ids($product->get_category_ids());
                        wp_set_object_terms($child_product->get_id(), $product->get_category_ids(), 'product_cat', true);

                        $child_product->save();
                    }
                    $parent_ids[] = $product_id;
                }
            }
            if (!empty($parent_ids)) {

                update_field('exclude_parent', $parent_ids, 'term_' . $term->term_id);
            }
        } else {
            echo 'укажите категорию';
        }
        //        die;

    }

    public function update_variable_php()
    {
        $type = 'variable';
        $products = wc_get_products(['type' => $type, 'limit' => -1]);
        foreach ($products as $product) {
            $parent_sku = $product->get_sku();
            $variable = new WC_Product_Variable($product->get_id());
            $variables = $variable->get_children();
            foreach ($variables as $key => $variable) {
                $product_variable = wc_get_product($variable);
                $product_variable->set_sku($parent_sku . '_' . $key);
                $product_variable->save();
            }
        }
        echo "finish\n";
    }
    public function remove_products_from_cat(){
        $uploaddir = wp_upload_dir()['basedir'];
        $folder = $uploaddir . '/xml/';
        $arrayfiles = scandir($folder);
        $reader = new XMLReader();
        $skus = [];
        $xml_skus = [];
        $cats = [];
        foreach ($arrayfiles as $key => $file) {
            if (mime_content_type($folder . $file) == "text/xml") {
                $reader->open($folder . $file);
                $reader->read();
                if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'VigrNom') {
                    $doc = new DOMDocument('1.0', 'UTF-8');
                    $xml = simplexml_import_dom($doc->importNode($reader->expand(), true));
                    foreach ($xml as $product) {
                        $cats[] = (string)$product->attributes()->Gr3;
                        $cats[] = (string)$product->attributes()->Gr2;
                        $cats[] = (string)$product->attributes()->Gr1;
                        $xml_skus[] = (string)$product->attributes()->Kod;
                    }
                }
            }
        }
        $term_slugs = [];
        $search_cats = array_unique($cats);
        foreach($search_cats as $cat){
            $term_cat = get_term_by('name',$cat,'product_cat');
            if(!empty($term_cat)){
                $term_slugs[] = $term_cat->slug;
            }

        }
        // Get shirts.
$args = array(
    'category' => $term_slugs,
    'limit' => -1,
);
$products = wc_get_products( $args );
$skus = [];
foreach($products as $product){
            if($product->get_sku()){
                $skus[] = $product->get_sku();
            }
        }  
    $this->logger_data($skus, 'skus.log');
    $this->logger_data($xml_skus, 'xml_skus.log');
    $result = array_diff($skus, $xml_skus);
    $this->logger_data($result, 'xml_remove.log');
    foreach($result as $sku_remove){
     $id =    wc_get_product_id_by_sku($sku_remove);
        if(!empty($id)){
            var_dump($id);
            wp_trash_post($id);
            
        }
    }
    }
    }

if (!empty($argv)) {
    if (in_array('run', $argv)) {
        $Wc_Data = new WOO_I1C_Application();
        $Wc_Data->upload_xml();
    }
    if (in_array('update_image', $argv)) {
        $Wc_Data = new WOO_I1C_Application();
        $Wc_Data->update_image_wp();
    }
    if (in_array('auto_update', $argv) && !empty($argv[2])) {
        $Wc_Data = new WOO_I1C_Application();
        $Wc_Data->auto_update_wp($argv[2]);
    }
    if (in_array('xml_update', $argv)) {
        $Wc_Data = new WOO_I1C_Application();
        $Wc_Data->xml_update();
    }
    if (in_array('update_variable_php', $argv)) {
        $Wc_Data = new WOO_I1C_Application();
        $Wc_Data->update_variable_php();
    }
    if (in_array('update_term_php', $argv)) {
        $Wc_Data = new WOO_I1C_Application();
        $Wc_Data->update_term($argv[2]);
    }
    if (in_array('get_all_terms', $argv)) {
        $Wc_Data = new WOO_I1C_Application();
        $Wc_Data->get_all_terms();
    }
    if (in_array('get_items', $argv)) {
        $Wc_Data = new WOO_I1C_Application();
        $Wc_Data->getItems();
    }
        if (in_array('remove_products', $argv)) {
        $Wc_Data = new WOO_I1C_Application();
        $Wc_Data->remove_products_from_cat();
    }
}
