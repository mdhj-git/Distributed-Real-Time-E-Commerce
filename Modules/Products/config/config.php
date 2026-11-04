<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Product image disk
    |--------------------------------------------------------------------------
    |
    | Product images are public content, so the default disk must be served
    | through the storage symlink. This is the only place the disk is read.
    |
    */

    'image_disk' => env('PRODUCT_IMAGES_DISK', 'public'),

];
