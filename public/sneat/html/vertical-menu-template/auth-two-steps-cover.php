<!doctype html>














  
  
  
  
  
  
  
  
  















  
    <!-- =========================================================
* Sneat -  | v3.0.0
==============================================================

* Product Page: https://themeselection.com/item/sneat-dashboard-pro-bootstrap/
* Created by: ThemeSelection

      * License: You must have a valid license purchased in order to legally use the theme for your project.
    
* Copyright ThemeSelection (https://themeselection.com)

=========================================================
 -->
    <!-- beautify ignore:start -->
  


<html
  lang="en"
  class=" layout-wide  customizer-hide"
  dir="ltr"
  data-skin="default"
  data-assets-path="../../assets/"
  data-template="vertical-menu-template"
  data-bs-theme="light">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
    <meta name="robots" content="noindex, nofollow" />

    
      <title>Demo: Two Steps Verifications Cover - Pages | Sneat - Bootstrap Dashboard PRO</title>
    

    
      <!-- Canonical SEO -->
      <meta name="description" content="Sneat is the best bootstrap 5 dashboard for responsive web apps. Streamline your app development process with ease." />
      
        <meta name="keywords" content="Sneat bootstrap dashboard, sneat bootstrap 5 dashboard, themeselection, html dashboard, web dashboard, frontend dashboard, responsive bootstrap theme" />
        <meta property="og:title" content="Sneat Bootstrap 5 Dashboard PRO by ThemeSelection" />
        <meta property="og:type" content="product" />
        <meta property="og:url" content="https://themeselection.com/item/sneat-dashboard-pro-bootstrap/" />
        <meta property="og:image" content="https://themeselection.com/wp-content/uploads/edd/2024/08/sneat-dashboard-pro-bootstrap-smm-image.png" />
        <meta property="og:description" content="Sneat is the best bootstrap 5 dashboard for responsive web apps. Streamline your app development process with ease." />
        <meta property="og:site_name" content="ThemeSelection" />
        <link rel="canonical" href="https://themeselection.com/item/sneat-dashboard-pro-bootstrap/" />
<!-- Favicon -->
    <link rel="icon" type="image/png" href="../../assets/img/logo.png?v=20260220" />

    <!-- Fonts -->
<link rel="stylesheet" href="../../assets/vendor/fonts/iconify-icons.css" />

    <!-- Core CSS -->
    <!-- build:css assets/vendor/css/theme.css  -->
    
      
      <link rel="stylesheet" href="../../assets/vendor/libs/pickr/pickr-themes.css" />
    
    <link rel="stylesheet" href="../../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../../assets/css/demo.css" />
    <link rel="stylesheet" href="../../assets/css/brand.css" />

    
    <!-- Vendors CSS -->
    
      <link rel="stylesheet" href="../../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    
    <!-- endbuild -->

    <!-- Vendor -->
  <link rel="stylesheet" href="../../assets/vendor/libs/@form-validation/form-validation.css" />

    <!-- Page CSS -->
    <!-- Page -->
  <link rel="stylesheet" href="../../assets/vendor/css/pages/page-auth.css" />

    <link rel="stylesheet" href="../../fonts/figtree.css" />
    <!-- Helpers -->
    <script src="../../assets/vendor/js/helpers.js"></script>
    <!--! Template customizer & Theme config files MUST be included after core stylesheets and helpers.js in the <head> section -->
    
      <!--? Template customizer: To hide customizer set displayCustomizer value false in config.js.  -->
      <script src="../../assets/vendor/js/template-customizer.js"></script>
    
    <!--? Config:  Mandatory theme config file contain global vars & default theme options, Set your preferred theme option in this file.  -->
    
      <script src="../../assets/js/config.js"></script>
    
  </head>

  <body>
<!-- Content -->

  <div class="authentication-wrapper authentication-cover">
    <!-- Logo -->
    <a href="index.html" class="app-brand auth-cover-brand gap-2">
      <span class="app-brand-logo demo">
        <img src="../../assets/img/logo.png" alt="APEWSD logo" class="app-brand-logo-img" />
      </span>
      <span class="app-brand-text demo text-heading fw-bold">APEWSD</span>
    </a>
    <!-- /Logo -->
    <div class="authentication-inner row m-0">
      <!-- /Left Text -->
      <div class="d-none d-lg-flex col-lg-7 col-xl-8 align-items-center p-5">
        <div class="w-100 d-flex justify-content-center">
          <img src="../../assets/img/illustrations/girl-verify-password-light.png" class="img-fluid scaleX-n1-rtl" alt="Login image" width="700" data-app-dark-img="illustrations/girl-verify-password-dark.png" data-app-light-img="illustrations/girl-verify-password-light.png" />
        </div>
      </div>
      <!-- /Left Text -->

      <!-- Two Steps Verification -->
      <div class="d-flex col-12 col-lg-5 col-xl-4 align-items-center authentication-bg p-sm-12 p-6">
        <div class="w-px-400 mx-auto mt-sm-12 mt-8">
          <h4 class="mb-1">Two Step Verification 💬</h4>
          <p class="text-start mb-6">
            We sent a verification code to your mobile. Enter the code from the mobile in the field below.
            <span class="fw-medium d-block mt-1 text-heading">******1234</span>
          </p>
          <p class="mb-0">Type your 6 digit security code</p>
          <form id="twoStepsForm" action="index.html" method="GET">
            <div class="mb-6 form-control-validation">
              <div class="auth-input-wrapper d-flex align-items-center justify-content-between numeral-mask-wrapper">
                <input type="tel" class="form-control auth-input h-px-50 text-center numeral-mask mx-sm-1 my-2" maxlength="1" autofocus />
                <input type="tel" class="form-control auth-input h-px-50 text-center numeral-mask mx-sm-1 my-2" maxlength="1" />
                <input type="tel" class="form-control auth-input h-px-50 text-center numeral-mask mx-sm-1 my-2" maxlength="1" />
                <input type="tel" class="form-control auth-input h-px-50 text-center numeral-mask mx-sm-1 my-2" maxlength="1" />
                <input type="tel" class="form-control auth-input h-px-50 text-center numeral-mask mx-sm-1 my-2" maxlength="1" />
                <input type="tel" class="form-control auth-input h-px-50 text-center numeral-mask mx-sm-1 my-2" maxlength="1" />
              </div>
              <!-- Create a hidden field which is combined by 3 fields above -->
              <input type="hidden" name="otp" />
            </div>
            <button class="btn btn-primary d-grid w-100 mb-6">Verify my account</button>
            <div class="text-center">
              Didn't get the code?
              <a href="javascript:void(0);">Resend</a>
            </div>
          </form>
        </div>
      </div>
      <!-- /Two Steps Verification -->
    </div>
  </div>

<!-- / Content -->

    
      <div class="buy-now">
        <a href="https://themeselection.com/item/sneat-dashboard-pro-bootstrap/" target="_blank" class="btn btn-danger btn-buy-now">Buy Now</a>
      </div>
    

    

    <!-- Core JS -->
    <!-- build:js assets/vendor/js/theme.js  -->
    
    
      <script src="../../assets/vendor/libs/jquery/jquery.js"></script>
    
    <script src="../../assets/vendor/libs/popper/popper.js"></script>
    <script src="../../assets/vendor/js/bootstrap.js"></script>
    <script src="../../assets/vendor/libs/@algolia/autocomplete-js.js"></script>

    
      
      <script src="../../assets/vendor/libs/pickr/pickr.js"></script>
    

    
      <script src="../../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
      
        
        <script src="../../assets/vendor/libs/hammer/hammer.js"></script>
        
          <script src="../../assets/vendor/libs/i18n/i18n.js"></script>
        
      
      <script src="../../assets/vendor/js/menu.js"></script>
    
    <!-- endbuild -->

    <!-- Vendors JS -->
    <script src="../../assets/vendor/libs/cleave-zen/cleave-zen.js"></script>
  <script src="../../assets/vendor/libs/@form-validation/popular.js"></script>
  <script src="../../assets/vendor/libs/@form-validation/bootstrap5.js"></script>
  <script src="../../assets/vendor/libs/@form-validation/auto-focus.js"></script>

    <!-- Main JS -->
    
      <script src="../../assets/js/main.js"></script>
    

    <!-- Page JS -->
    <script src="../../assets/js/pages-auth.js"></script>
  <script src="../../assets/js/pages-auth-two-steps.js"></script>
    
  </body>
</html>

  <!-- beautify ignore:end -->

