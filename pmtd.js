const successCallback = function() {
	const checkoutForm = jQuery( 'form.woocommerce-checkout' );

	// deactivate the getUserPaymentSystems function event
	checkoutForm.off( 'checkout_place_order', getUserPaymentSystems );

	// submit the form now
	checkoutForm.submit();
}

const errorCallback = function(error) {
  console.error('Payment gateway error: ' + error);
}

const getUserPaymentSystems = async function() {

  const userEmail = jQuery('input[name="billing_email"]').val();
  const input = jQuery('input[name="pt_payment_system"]');
  let userId, paymentSystems = [];

  if (userEmail) {
    userId = await getUserIdByEmail(userEmail);
  }

  if (userId) {
    paymentSystems = await getPaymentSystems(userId);
  }
  
  if (paymentSystems.length) {
    if (paymentSystems.length > 1) {
      let options = {};
      paymentSystems.map(i => options[i.id_payment_system] = i.name);
      const { value: paymentSystem } = await Swal.fire({
        title: "Select a payment system",
        input: "select",
        inputOptions: options,
        inputPlaceholder: "Select a payment system",
        showCancelButton: true,
        inputValue: Object.keys(options)[0],
        inputValidator: (value) => {
          return new Promise((resolve) => {
            if (!value) {
              resolve("Please select a payment system");
            } else {
              resolve();
            }
          });
        }
      });
      if (paymentSystem) {
        input.val(paymentSystem);
        successCallback();
      }
    } else {
      input.val(paymentSystems[0].id_payment_system);
      successCallback();
    }
  } else {
    errorCallback('Payment systems not found.');
  }
  
	return false;
}

const getUserIdByEmail = async function(email) {
  await new Promise(resolve => setTimeout(resolve, 3000));
  try {
    const response = await fetch(`${pmtd_params.endpoint}clients/find/email?email=${email}`);
    if (response.ok) { 
      let json = await response.json();
      if (!json.error) {
        return json.data.id_client;
      } else {
        errorCallback(json.error.message);
      }
    } else {
      errorCallback("Error HTTP: " + response.status);
    }
  } catch(e) {
    errorCallback(e);
  }
  return false;
}

const getPaymentSystems = async function(id) {
  try {
    const response = await fetch(`${pmtd_params.endpoint}clients/payment-systems?id_client=${id}`);
    if (response.ok) { 
      let json = await response.json();
      if (!json.error) {
        return json.data.payment_systems;
      } else {
        errorCallback(json.error.message);
      }
    } else {
      errorCallback("Error HTTP: " + response.status);
    }
  } catch(e) {
    errorCallback(e);
  }
  return false;
}

jQuery( function($){
	const checkoutForm = $( 'form.woocommerce-checkout' );
  const input = $('#payment_method_payments_today');
  if (input.is(':checked')) {
    checkoutForm.on( 'checkout_place_order', getUserPaymentSystems);
  }
});