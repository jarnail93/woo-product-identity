jQuery(document).ready(function() {

  let dialog_title = 'Product not verified';
  let dialog_class = 'danger';

  if(product_info.hasOwnProperty('found') && product_info.found) {

    dialog_title = 'Product Verified';
    dialog_class = 'success';

    jQuery('body').append(`
      <div id="wpi-product-info" class="${dialog_class}">
        <dl>
          ${Object.keys(product_info.data).map(idx => `
            <dt>${idx}</dt>
            <dd>${product_info.data[idx]}</dd>
          `).join('')}
        </dl>
      </div>
    `);

    jQuery('#wpi-product-info').dialog({
      title: dialog_title,
      modal: true
    });

  }

});