# Billplz for Educator

Accept payment using Billplz by using this plugin.

## System Requirements
* PHP Version **7.0** or later
* Build with **Educator** version **2.0.3**
* URL: https://wordpress.org/plugins/educator/

## Installation

-  Copy all files to installation educator directory. Usually located at:
   ```
    /wp-content/plugins/educator
   ```
-  Edit file: __*includes/Edr/Main.php*__. Add this element to $gateways array:
    ```php
    'billplz' => array( 'class' => 'Edr_Gateway_Billplz' ),
    ```

## Configuration

1. **Educator Settings** >> **Billplz**
2. Set **Enable**, **API Key**, **X Signature Key**, **Thank you message** & Collection ID
3. Save Changes

## Troubleshooting

* Please make sure you have enabled X Signature Key properly on your [Billplz Account Settings](https://www.billplz.com/enterprise/setting)

## Other

Facebook: [Billplz Dev Jam](https://www.facebook.com/groups/billplzdevjam/)
