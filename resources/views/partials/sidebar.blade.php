@php
  $authUser = auth()->user();
  $isAdminUser = $authUser?->isAdmin() ?? false;
  $canAccessMachineBlueprint = $authUser && ($authUser->isAdmin() || $authUser->isOwner() || $authUser->isStaff());
  $isOwnerUser = $authUser?->isOwner() ?? false;
  $canAccessInventory = $authUser && ($authUser->isOwner() || $authUser->isStaff());
  $canAccessBatchMonitoring = $authUser && ($authUser->isAdmin() || $authUser->isOwner() || $authUser->isStaff());
  $canAccessEggRecordExplorer = $authUser && ($authUser->isAdmin() || $authUser->isOwner() || $authUser->isStaff());
  $canAccessProductionReports = $authUser && ($authUser->isAdmin() || $authUser->isOwner() || $authUser->isStaff());
  $canAccessValidationAccuracy = $authUser && ($authUser->isAdmin() || $authUser->isOwner() || $authUser->isStaff());
  $canAccessMonitoringNotifications = $authUser && ($authUser->isAdmin() || $authUser->isOwner() || $authUser->isStaff());
  $canAccessPriceMonitoring = $authUser && ($authUser->isAdmin() || $authUser->isCustomer());
@endphp

<aside id="layout-menu" class="layout-menu menu-vertical menu">
  
  <div class="app-brand demo">
    <a href="{{ route('dashboard') }}" class="app-brand-link">
      <span class="app-brand-logo demo">
        <img src="{{ $sneatAssetsBase }}/img/logo.png?v=20260226" alt="Site logo" class="app-brand-logo-img" />
      </span>
    </a>

    <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large ms-auto" aria-label="Collapse sidebar">
      <span class="app-shell-chevron-left" aria-hidden="true"></span>
    </a>
  </div>

  <div class="menu-inner-shadow"></div>

  
  
    <ul class="menu-inner py-1">
      @if ($isAdminUser)
        <li class="menu-header small">
          <span class="menu-header-text">Administration</span>
        </li>
        <li class="menu-item {{ request()->routeIs('admin.users.index', 'admin.users.create', 'admin.users.edit') ? 'active' : '' }}">
          <a href="{{ route('admin.users.index') }}" class="menu-link">
            <span class="app-shell-sidebar-dusk">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/admin/icons/icons8-user-shield.png',
                'alt' => 'Users',
                'classes' => 'menu-icon app-shell-icon--admin-menu',
              ])
            </span>
            <div>User List</div>
          </a>
        </li>
        <li class="menu-item {{ request()->routeIs('admin.devices.*') ? 'active' : '' }}">
          <a href="{{ route('admin.devices.index') }}" class="menu-link">
            <span class="app-shell-sidebar-dusk">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/server/icons/icons8-device-manager.png',
                'alt' => 'Devices',
                'classes' => 'menu-icon app-shell-icon--admin-menu',
              ])
            </span>
            <div>Devices</div>
          </a>
        </li>
        <li class="menu-item {{ request()->routeIs('admin.settings.*') ? 'active open' : '' }}">
          <a href="javascript:void(0);" class="menu-link menu-toggle">
            <span class="app-shell-sidebar-dusk">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/gear/icons/icons8-settings.png',
                'alt' => 'Settings',
                'classes' => 'menu-icon app-shell-icon--admin-menu',
              ])
            </span>
            <div>Settings</div>
          </a>
          <ul class="menu-sub">
            <li class="menu-item {{ request()->routeIs('admin.settings.edit') ? 'active' : '' }}">
              <a href="{{ route('admin.settings.edit') }}" class="menu-link settings-submenu-link">
                <div class="settings-submenu-copy">
                  <span>General Settings</span>
                  <small class="text-muted">Typography and page visibility</small>
                </div>
                <span class="badge bg-label-primary rounded-pill settings-submenu-badge">Core</span>
              </a>
            </li>
            <li class="menu-item {{ request()->routeIs('admin.settings.access-boundary') ? 'active' : '' }}">
              <a href="{{ route('admin.settings.access-boundary') }}" class="menu-link settings-submenu-link">
                <div class="settings-submenu-copy">
                  <span>Access Boundary</span>
                  <small class="text-muted">System geofence rules</small>
                </div>
                <span class="badge bg-label-success rounded-pill settings-submenu-badge">Guard</span>
              </a>
            </li>
            <li class="menu-item {{ request()->routeIs('admin.settings.location-overview') ? 'active' : '' }}">
              <a href="{{ route('admin.settings.location-overview') }}" class="menu-link settings-submenu-link">
                <div class="settings-submenu-copy">
                  <span>Location Overview</span>
                  <small class="text-muted">All farm markers and overlay</small>
                </div>
                <span class="badge bg-label-info rounded-pill settings-submenu-badge">Map</span>
              </a>
            </li>
          </ul>
        </li>
        <li class="menu-item {{ request()->routeIs('admin.maps.farms') ? 'active' : '' }}">
          <a href="{{ route('admin.maps.farms') }}" class="menu-link">
            <span class="app-shell-sidebar-dusk">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/address/icons/icons8-place-marker--v3.png',
                'alt' => 'Farm and map',
                'classes' => 'menu-icon app-shell-icon--admin-menu',
              ])
            </span>
            <div>Farm &amp; Map</div>
          </a>
        </li>
      @endif

      <li class="menu-item {{ request()->routeIs('guides.index') ? 'active' : '' }}">
        <a href="{{ route('guides.index') }}" class="menu-link">
          <span class="app-shell-sidebar-dusk">
            @include('partials.curated-shell-icon', [
              'src' => 'resources/icons/dusk/book/animated/icons8-open-book--v2.gif',
              'alt' => 'Guide center',
              'classes' => 'menu-icon app-shell-icon--admin-menu',
            ])
          </span>
          <div>Guide Center</div>
        </a>
      </li>

      @if ($canAccessPriceMonitoring)
        <li class="menu-item {{ request()->routeIs('price-monitoring.index') ? 'active' : '' }}">
          <a href="{{ route('price-monitoring.index') }}" class="menu-link">
            <span class="app-shell-sidebar-dusk">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/bank/animated/icons8-money-box--v2.gif',
                'alt' => 'Price monitoring',
                'classes' => 'menu-icon app-shell-icon--admin-menu',
              ])
            </span>
            <div>Price Monitoring</div>
          </a>
        </li>
      @endif

      @if ($isOwnerUser)
        <li class="menu-item {{ request()->routeIs('owner.farms.*') ? 'active' : '' }}">
          <a href="{{ route('owner.farms.index') }}" class="menu-link">
            <span class="app-shell-sidebar-dusk">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/man/icons/icons8-farmer-male.png',
                'alt' => 'My farms',
                'classes' => 'menu-icon app-shell-icon--admin-menu',
              ])
            </span>
            <div>My Farms</div>
          </a>
        </li>
      @endif

      @if ($canAccessInventory)
        <li class="menu-item {{ request()->routeIs('inventory.index') ? 'active' : '' }}">
          <a href="{{ route('inventory.index') }}" class="menu-link">
            <span class="app-shell-sidebar-dusk">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/book/icons/icons8-repository.png',
                'alt' => 'Inventory',
                'classes' => 'menu-icon app-shell-icon--admin-menu',
              ])
            </span>
            <div>Inventory</div>
          </a>
        </li>
      @endif

      @if ($canAccessBatchMonitoring)
        <li class="menu-item {{ request()->routeIs('monitoring.batches.*') ? 'active' : '' }}">
          <a href="{{ route('monitoring.batches.index') }}" class="menu-link">
            <span class="app-shell-sidebar-dusk">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/ecommerce/animated/icons8-box-important--v2.gif',
                'alt' => 'Batch monitoring',
                'classes' => 'menu-icon app-shell-icon--admin-menu',
              ])
            </span>
            <div>Batch Monitoring</div>
          </a>
        </li>
      @endif

      @if ($canAccessEggRecordExplorer)
        <li class="menu-item {{ request()->routeIs('monitoring.records.*') ? 'active' : '' }}">
          <a href="{{ route('monitoring.records.index') }}" class="menu-link">
            <span class="app-shell-sidebar-dusk">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/add/icons/icons8-add-database.png',
                'alt' => 'Egg record explorer',
                'classes' => 'menu-icon app-shell-icon--admin-menu',
              ])
            </span>
            <div>Egg Records</div>
          </a>
        </li>
      @endif

      @if ($canAccessProductionReports)
        <li class="menu-item {{ request()->routeIs('monitoring.reports.production.*') ? 'active' : '' }}">
          <a href="{{ route('monitoring.reports.production.index') }}" class="menu-link">
            <span class="app-shell-sidebar-dusk">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/business/animated/icons8-statistics--v2.gif',
                'alt' => 'Production reports',
                'classes' => 'menu-icon app-shell-icon--admin-menu',
              ])
            </span>
            <div>Production Reports</div>
          </a>
        </li>
      @endif

      @if ($canAccessValidationAccuracy)
        <li class="menu-item {{ request()->routeIs('monitoring.validation.*') ? 'active' : '' }}">
          <a href="{{ route('monitoring.validation.index') }}" class="menu-link">
            <span class="app-shell-sidebar-dusk">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/business/animated/icons8-accuracy--v2.gif',
                'alt' => 'Validation and accuracy',
                'classes' => 'menu-icon app-shell-icon--admin-menu',
              ])
            </span>
            <div>Validation &amp; Accuracy</div>
          </a>
        </li>
      @endif

      @if ($canAccessMonitoringNotifications)
        <li class="menu-item {{ request()->routeIs('monitoring.notifications.*') ? 'active' : '' }}">
          <a href="{{ route('monitoring.notifications.index') }}" class="menu-link">
            <span class="app-shell-sidebar-dusk">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/business/animated/icons8-appointment-reminders--v2.gif',
                'alt' => 'Notifications',
                'classes' => 'menu-icon app-shell-icon--admin-menu',
              ])
            </span>
            <div>Notifications</div>
          </a>
        </li>
      @endif

      @if ($canAccessMachineBlueprint)
        <li class="menu-item {{ request()->routeIs('machine-blueprint.index') ? 'active' : '' }}">
          <a href="{{ route('machine-blueprint.index') }}" class="menu-link">
            <span class="app-shell-sidebar-dusk">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/technology/icons/icons8-robot--v2.png',
                'alt' => 'Machine blueprint',
                'classes' => 'menu-icon app-shell-icon--admin-menu',
              ])
            </span>
            <div>Machine Blueprint</div>
          </a>
        </li>
      @endif

      <!-- Dashboards -->
      <li class="menu-item">
        <a href="javascript:void(0);" class="menu-link menu-toggle">
          <i class="menu-icon icon-base bx bx-home-smile"></i>
          <div data-i18n="Dashboards">Dashboards</div>
          <div class="badge text-bg-danger rounded-pill ms-auto">5</div>
        </a>
        <ul class="menu-sub">
          <li class="menu-item">
            <a href="dashboards-analytics.html" class="menu-link">
              <div data-i18n="Analytics">Analytics</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="dashboards-crm.html" class="menu-link">
              <div data-i18n="CRM">CRM</div>
            </a>
          </li>
          <li class="menu-item active">
            <a href="app-ecommerce-dashboard.html" class="menu-link">
              <div data-i18n="eCommerce">eCommerce</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="app-logistics-dashboard.html" class="menu-link">
              <div data-i18n="Logistics">Logistics</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="app-academy-dashboard.html" class="menu-link">
              <div data-i18n="Academy">Academy</div>
            </a>
          </li>
        </ul>
      </li>

      <!-- Layouts -->
      <li class="menu-item">
        <a href="javascript:void(0);" class="menu-link menu-toggle">
          <i class="menu-icon icon-base bx bx-layout"></i>
          <div data-i18n="Layouts">Layouts</div>
        </a>

        <ul class="menu-sub">
          <li class="menu-item">
            <a href="layouts-collapsed-menu.html" class="menu-link">
              <div data-i18n="Collapsed menu">Collapsed menu</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="layouts-content-navbar.html" class="menu-link">
              <div data-i18n="Content navbar">Content navbar</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="layouts-content-navbar-with-sidebar.html" class="menu-link">
              <div data-i18n="Content nav + Sidebar">Content nav + Sidebar</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="../horizontal-menu-template/" class="menu-link" target="_blank">
              <div data-i18n="Horizontal">Horizontal</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="layouts-without-menu.html" class="menu-link">
              <div data-i18n="Without menu">Without menu</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="layouts-without-navbar.html" class="menu-link">
              <div data-i18n="Without navbar">Without navbar</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="layouts-fluid.html" class="menu-link">
              <div data-i18n="Fluid">Fluid</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="layouts-container.html" class="menu-link">
              <div data-i18n="Container">Container</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="layouts-blank.html" class="menu-link">
              <div data-i18n="Blank">Blank</div>
            </a>
          </li>
        </ul>
      </li>

      <!-- Front Pages -->
      <li class="menu-item">
        <a href="javascript:void(0);" class="menu-link menu-toggle">
          <i class="menu-icon icon-base bx bx-store"></i>
          <div data-i18n="Front Pages">Front Pages</div>
        </a>
        <ul class="menu-sub">
          <li class="menu-item">
            <a href="../front-pages/landing-page.html" class="menu-link" target="_blank">
              <div data-i18n="Landing">Landing</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="../front-pages/pricing-page.html" class="menu-link" target="_blank">
              <div data-i18n="Pricing">Pricing</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="../front-pages/payment-page.html" class="menu-link" target="_blank">
              <div data-i18n="Payment">Payment</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="../front-pages/checkout-page.html" class="menu-link" target="_blank">
              <div data-i18n="Checkout">Checkout</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="../front-pages/help-center-landing.html" class="menu-link" target="_blank">
              <div data-i18n="Help Center">Help Center</div>
            </a>
          </li>
        </ul>
      </li>

      <!-- Apps & Pages -->
      <li class="menu-header small">
        <span class="menu-header-text" data-i18n="Apps & Pages">Apps &amp; Pages</span>
      </li>
      <li class="menu-item">
        <a href="app-email.html" class="menu-link">
          <i class="menu-icon icon-base bx bx-envelope"></i>
          <div data-i18n="Email">Email</div>
        </a>
      </li>
      <li class="menu-item">
        <a href="app-chat.html" class="menu-link">
          <i class="menu-icon icon-base bx bx-chat"></i>
          <div data-i18n="Chat">Chat</div>
        </a>
      </li>
      <li class="menu-item">
        <a href="app-calendar.html" class="menu-link">
          <i class="menu-icon icon-base bx bx-calendar"></i>
          <div data-i18n="Calendar">Calendar</div>
        </a>
      </li>
      <li class="menu-item">
        <a href="app-kanban.html" class="menu-link">
          <i class="menu-icon icon-base bx bx-grid"></i>
          <div data-i18n="Kanban">Kanban</div>
        </a>
      </li>
      <!-- e-commerce-app menu start -->
      <li class="menu-item active open">
        <a href="javascript:void(0);" class="menu-link menu-toggle">
          <i class="menu-icon icon-base bx bx-cart-alt"></i>
          <div data-i18n="eCommerce">eCommerce</div>
        </a>
        <ul class="menu-sub">
          <li class="menu-item active">
            <a href="app-ecommerce-dashboard.html" class="menu-link">
              <div data-i18n="Dashboard">Dashboard</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="javascript:void(0);" class="menu-link menu-toggle">
              <div data-i18n="Products">Products</div>
            </a>
            <ul class="menu-sub">
              <li class="menu-item">
                <a href="app-ecommerce-product-list.html" class="menu-link">
                  <div data-i18n="Product List">Product List</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="app-ecommerce-product-add.html" class="menu-link">
                  <div data-i18n="Add Product">Add Product</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="app-ecommerce-category-list.html" class="menu-link">
                  <div data-i18n="Category List">Category List</div>
                </a>
              </li>
            </ul>
          </li>
          <li class="menu-item">
            <a href="javascript:void(0);" class="menu-link menu-toggle">
              <div data-i18n="Order">Order</div>
            </a>
            <ul class="menu-sub">
              <li class="menu-item">
                <a href="app-ecommerce-order-list.html" class="menu-link">
                  <div data-i18n="Order List">Order List</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="app-ecommerce-order-details.html" class="menu-link">
                  <div data-i18n="Order Details">Order Details</div>
                </a>
              </li>
            </ul>
          </li>
          <li class="menu-item">
            <a href="javascript:void(0);" class="menu-link menu-toggle">
              <div data-i18n="Customer">Customer</div>
            </a>
            <ul class="menu-sub">
              <li class="menu-item">
                <a href="app-ecommerce-customer-all.html" class="menu-link">
                  <div data-i18n="All Customers">All Customers</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="javascript:void(0);" class="menu-link menu-toggle">
                  <div data-i18n="Customer Details">Customer Details</div>
                </a>
                <ul class="menu-sub">
                  <li class="menu-item">
                    <a href="app-ecommerce-customer-details-overview.html" class="menu-link">
                      <div data-i18n="Overview">Overview</div>
                    </a>
                  </li>
                  <li class="menu-item">
                    <a href="app-ecommerce-customer-details-security.html" class="menu-link">
                      <div data-i18n="Security">Security</div>
                    </a>
                  </li>
                  <li class="menu-item">
                    <a href="app-ecommerce-customer-details-billing.html" class="menu-link">
                      <div data-i18n="Address & Billing">Address & Billing</div>
                    </a>
                  </li>
                  <li class="menu-item">
                    <a href="app-ecommerce-customer-details-notifications.html" class="menu-link">
                      <div data-i18n="Notifications">Notifications</div>
                    </a>
                  </li>
                </ul>
              </li>
            </ul>
          </li>
          <li class="menu-item">
            <a href="app-ecommerce-manage-reviews.html" class="menu-link">
              <div data-i18n="Manage Reviews">Manage Reviews</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="app-ecommerce-referral.html" class="menu-link">
              <div data-i18n="Referrals">Referrals</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="javascript:void(0);" class="menu-link menu-toggle">
              <div data-i18n="Settings">Settings</div>
            </a>
            <ul class="menu-sub">
              <li class="menu-item">
                <a href="app-ecommerce-settings-detail.html" class="menu-link">
                  <div data-i18n="Store Details">Store Details</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="app-ecommerce-settings-payments.html" class="menu-link">
                  <div data-i18n="Payments">Payments</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="app-ecommerce-settings-checkout.html" class="menu-link">
                  <div data-i18n="Checkout">Checkout</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="app-ecommerce-settings-shipping.html" class="menu-link">
                  <div data-i18n="Shipping & Delivery">Shipping & Delivery</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="app-ecommerce-settings-locations.html" class="menu-link">
                  <div data-i18n="Locations">Locations</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="app-ecommerce-settings-notifications.html" class="menu-link">
                  <div data-i18n="Notifications">Notifications</div>
                </a>
              </li>
            </ul>
          </li>
        </ul>
      </li>
      <!-- e-commerce-app menu end -->
      <!-- Academy menu start -->
      <li class="menu-item">
        <a href="javascript:void(0);" class="menu-link menu-toggle">
          <i class="menu-icon icon-base bx bx-book-open"></i>
          <div data-i18n="Academy">Academy</div>
        </a>
        <ul class="menu-sub">
          <li class="menu-item">
            <a href="app-academy-dashboard.html" class="menu-link">
              <div data-i18n="Dashboard">Dashboard</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="app-academy-course.html" class="menu-link">
              <div data-i18n="My Course">My Course</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="app-academy-course-details.html" class="menu-link">
              <div data-i18n="Course Details">Course Details</div>
            </a>
          </li>
        </ul>
      </li>
      <!-- Academy menu end -->
      <li class="menu-item">
        <a href="javascript:void(0);" class="menu-link menu-toggle">
          <i class="menu-icon icon-base bx bx-car"></i>
          <div data-i18n="Logistics">Logistics</div>
        </a>
        <ul class="menu-sub">
          <li class="menu-item">
            <a href="app-logistics-dashboard.html" class="menu-link">
              <div data-i18n="Dashboard">Dashboard</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="app-logistics-fleet.html" class="menu-link">
              <div data-i18n="Fleet">Fleet</div>
            </a>
          </li>
        </ul>
      </li>
      <li class="menu-item">
        <a href="javascript:void(0);" class="menu-link menu-toggle">
          <i class="menu-icon icon-base bx bx-food-menu"></i>
          <div data-i18n="Invoice">Invoice</div>
        </a>
        <ul class="menu-sub">
          <li class="menu-item">
            <a href="app-invoice-list.html" class="menu-link">
              <div data-i18n="List">List</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="app-invoice-preview.html" class="menu-link">
              <div data-i18n="Preview">Preview</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="app-invoice-edit.html" class="menu-link">
              <div data-i18n="Edit">Edit</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="app-invoice-add.html" class="menu-link">
              <div data-i18n="Add">Add</div>
            </a>
          </li>
        </ul>
      </li>
      <li class="menu-item">
        <a href="javascript:void(0);" class="menu-link menu-toggle">
          <i class="menu-icon icon-base bx bx-user"></i>
          <div data-i18n="Users">Users</div>
        </a>
        <ul class="menu-sub">
          <li class="menu-item">
            <a href="app-user-list.html" class="menu-link">
              <div data-i18n="List">List</div>
            </a>
          </li>

          <li class="menu-item">
            <a href="javascript:void(0);" class="menu-link menu-toggle">
              <div data-i18n="View">View</div>
            </a>
            <ul class="menu-sub">
              <li class="menu-item">
                <a href="app-user-view-account.html" class="menu-link">
                  <div data-i18n="Account">Account</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="app-user-view-security.html" class="menu-link">
                  <div data-i18n="Security">Security</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="app-user-view-billing.html" class="menu-link">
                  <div data-i18n="Billing & Plans">Billing & Plans</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="app-user-view-notifications.html" class="menu-link">
                  <div data-i18n="Notifications">Notifications</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="app-user-view-connections.html" class="menu-link">
                  <div data-i18n="Connections">Connections</div>
                </a>
              </li>
            </ul>
          </li>
        </ul>
      </li>
      <li class="menu-item">
        <a href="javascript:void(0);" class="menu-link menu-toggle">
          <i class="menu-icon icon-base bx bx-check-shield"></i>
          <div data-i18n="Roles & Permissions">Roles & Permissions</div>
        </a>
        <ul class="menu-sub">
          <li class="menu-item">
            <a href="app-access-roles.html" class="menu-link">
              <div data-i18n="Roles">Roles</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="app-access-permission.html" class="menu-link">
              <div data-i18n="Permission">Permission</div>
            </a>
          </li>
        </ul>
      </li>
      <li class="menu-item">
        <a href="javascript:void(0);" class="menu-link menu-toggle">
          <i class="menu-icon icon-base bx bx-dock-top"></i>
          <div data-i18n="Pages">Pages</div>
        </a>
        <ul class="menu-sub">
          <li class="menu-item">
            <a href="javascript:void(0);" class="menu-link menu-toggle">
              <div data-i18n="User Profile">User Profile</div>
            </a>
            <ul class="menu-sub">
              <li class="menu-item">
                <a href="pages-profile-user.html" class="menu-link">
                  <div data-i18n="Profile">Profile</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="pages-profile-teams.html" class="menu-link">
                  <div data-i18n="Teams">Teams</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="pages-profile-projects.html" class="menu-link">
                  <div data-i18n="Projects">Projects</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="pages-profile-connections.html" class="menu-link">
                  <div data-i18n="Connections">Connections</div>
                </a>
              </li>
            </ul>
          </li>
          <li class="menu-item">
            <a href="javascript:void(0);" class="menu-link menu-toggle">
              <div data-i18n="Account Settings">Account Settings</div>
            </a>
            <ul class="menu-sub">
              <li class="menu-item">
                <a href="pages-account-settings-account.html" class="menu-link">
                  <div data-i18n="Account">Account</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="pages-account-settings-security.html" class="menu-link">
                  <div data-i18n="Security">Security</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="pages-account-settings-billing.html" class="menu-link">
                  <div data-i18n="Billing & Plans">Billing & Plans</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="pages-account-settings-notifications.html" class="menu-link">
                  <div data-i18n="Notifications">Notifications</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="pages-account-settings-connections.html" class="menu-link">
                  <div data-i18n="Connections">Connections</div>
                </a>
              </li>
            </ul>
          </li>
          <li class="menu-item">
            <a href="pages-faq.html" class="menu-link">
              <div data-i18n="FAQ">FAQ</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="pages-pricing.html" class="menu-link">
              <div data-i18n="Pricing">Pricing</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="javascript:void(0);" class="menu-link menu-toggle">
              <div data-i18n="Misc">Misc</div>
            </a>
            <ul class="menu-sub">
              <li class="menu-item">
                <a href="pages-misc-error.html" class="menu-link" target="_blank">
                  <div data-i18n="Error">Error</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="pages-misc-under-maintenance.html" class="menu-link" target="_blank">
                  <div data-i18n="Under Maintenance">Under Maintenance</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="pages-misc-comingsoon.html" class="menu-link" target="_blank">
                  <div data-i18n="Coming Soon">Coming Soon</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="pages-misc-not-authorized.html" class="menu-link" target="_blank">
                  <div data-i18n="Not Authorized">Not Authorized</div>
                </a>
              </li>
            </ul>
          </li>
        </ul>
      </li>
      <li class="menu-item">
        <a href="javascript:void(0);" class="menu-link menu-toggle">
          <i class="menu-icon icon-base bx bx-lock-open-alt"></i>
          <div data-i18n="Authentications">Authentications</div>
        </a>
        <ul class="menu-sub">
          <li class="menu-item">
            <a href="javascript:void(0);" class="menu-link menu-toggle">
              <div data-i18n="Login">Login</div>
            </a>
            <ul class="menu-sub">
              <li class="menu-item">
                <a href="auth-login-basic.html" class="menu-link" target="_blank">
                  <div data-i18n="Basic">Basic</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="auth-login-cover.html" class="menu-link">
                  <div data-i18n="Cover">Cover</div>
                </a>
              </li>
            </ul>
          </li>
          <li class="menu-item">
            <a href="javascript:void(0);" class="menu-link menu-toggle">
              <div data-i18n="Register">Register</div>
            </a>
            <ul class="menu-sub">
              <li class="menu-item">
                <a href="auth-register-basic.html" class="menu-link" target="_blank">
                  <div data-i18n="Basic">Basic</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="auth-register-cover.html" class="menu-link" target="_blank">
                  <div data-i18n="Cover">Cover</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="auth-register-multisteps.html" class="menu-link" target="_blank">
                  <div data-i18n="Multi-steps">Multi-steps</div>
                </a>
              </li>
            </ul>
          </li>
          <li class="menu-item">
            <a href="javascript:void(0);" class="menu-link menu-toggle">
              <div data-i18n="Verify Email">Verify Email</div>
            </a>
            <ul class="menu-sub">
              <li class="menu-item">
                <a href="auth-verify-email-basic.html" class="menu-link" target="_blank">
                  <div data-i18n="Basic">Basic</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="auth-verify-email-cover.html" class="menu-link" target="_blank">
                  <div data-i18n="Cover">Cover</div>
                </a>
              </li>
            </ul>
          </li>
          <li class="menu-item">
            <a href="javascript:void(0);" class="menu-link menu-toggle">
              <div data-i18n="Reset Password">Reset Password</div>
            </a>
            <ul class="menu-sub">
              <li class="menu-item">
                <a href="auth-reset-password-basic.html" class="menu-link" target="_blank">
                  <div data-i18n="Basic">Basic</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="auth-reset-password-cover.html" class="menu-link" target="_blank">
                  <div data-i18n="Cover">Cover</div>
                </a>
              </li>
            </ul>
          </li>
          <li class="menu-item">
            <a href="javascript:void(0);" class="menu-link menu-toggle">
              <div data-i18n="Forgot Password">Forgot Password</div>
            </a>
            <ul class="menu-sub">
              <li class="menu-item">
                <a href="auth-forgot-password-basic.html" class="menu-link" target="_blank">
                  <div data-i18n="Basic">Basic</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="auth-forgot-password-cover.html" class="menu-link" target="_blank">
                  <div data-i18n="Cover">Cover</div>
                </a>
              </li>
            </ul>
          </li>
          <li class="menu-item">
            <a href="javascript:void(0);" class="menu-link menu-toggle">
              <div data-i18n="Two Steps">Two Steps</div>
            </a>
            <ul class="menu-sub">
              <li class="menu-item">
                <a href="auth-two-steps-basic.html" class="menu-link" target="_blank">
                  <div data-i18n="Basic">Basic</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="auth-two-steps-cover.html" class="menu-link" target="_blank">
                  <div data-i18n="Cover">Cover</div>
                </a>
              </li>
            </ul>
          </li>
        </ul>
      </li>
      <li class="menu-item">
        <a href="javascript:void(0);" class="menu-link menu-toggle">
          <i class="menu-icon icon-base bx bx-spreadsheet"></i>
          <div data-i18n="Wizard Examples">Wizard Examples</div>
        </a>
        <ul class="menu-sub">
          <li class="menu-item">
            <a href="wizard-ex-checkout.html" class="menu-link">
              <div data-i18n="Checkout">Checkout</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="wizard-ex-property-listing.html" class="menu-link">
              <div data-i18n="Property Listing">Property Listing</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="wizard-ex-create-deal.html" class="menu-link">
              <div data-i18n="Create Deal">Create Deal</div>
            </a>
          </li>
        </ul>
      </li>
      <li class="menu-item">
        <a href="modal-examples.html" class="menu-link">
          <i class="menu-icon icon-base bx bx-window-open"></i>
          <div data-i18n="Modal Examples">Modal Examples</div>
        </a>
      </li>

      <!-- Components -->
      <li class="menu-header small">
        <span class="menu-header-text" data-i18n="Components">Components</span>
      </li>
      <!-- Cards -->
      <li class="menu-item">
        <a href="javascript:void(0);" class="menu-link menu-toggle">
          <i class="menu-icon icon-base bx bx-collection"></i>
          <div data-i18n="Cards">Cards</div>
          <div class="badge text-bg-primary rounded-pill ms-auto">6</div>
        </a>
        <ul class="menu-sub">
          <li class="menu-item">
            <a href="cards-basic.html" class="menu-link">
              <div data-i18n="Basic">Basic</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="cards-advance.html" class="menu-link">
              <div data-i18n="Advance">Advance</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="cards-statistics.html" class="menu-link">
              <div data-i18n="Statistics">Statistics</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="cards-analytics.html" class="menu-link">
              <div data-i18n="Analytics">Analytics</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="cards-gamifications.html" class="menu-link">
              <div data-i18n="Gamifications">Gamifications</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="cards-actions.html" class="menu-link">
              <div data-i18n="Actions">Actions</div>
            </a>
          </li>
        </ul>
      </li>
      <!-- User interface -->
      <li class="menu-item">
        <a href="javascript:void(0)" class="menu-link menu-toggle">
          <i class="menu-icon icon-base bx bx-box"></i>
          <div data-i18n="User interface">User interface</div>
        </a>
        <ul class="menu-sub">
          <li class="menu-item">
            <a href="ui-accordion.html" class="menu-link">
              <div data-i18n="Accordion">Accordion</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="ui-alerts.html" class="menu-link">
              <div data-i18n="Alerts">Alerts</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="ui-badges.html" class="menu-link">
              <div data-i18n="Badges">Badges</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="ui-buttons.html" class="menu-link">
              <div data-i18n="Buttons">Buttons</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="ui-carousel.html" class="menu-link">
              <div data-i18n="Carousel">Carousel</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="ui-collapse.html" class="menu-link">
              <div data-i18n="Collapse">Collapse</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="ui-dropdowns.html" class="menu-link">
              <div data-i18n="Dropdowns">Dropdowns</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="ui-footer.html" class="menu-link">
              <div data-i18n="Footer">Footer</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="ui-list-groups.html" class="menu-link">
              <div data-i18n="List Groups">List groups</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="ui-modals.html" class="menu-link">
              <div data-i18n="Modals">Modals</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="ui-navbar.html" class="menu-link">
              <div data-i18n="Navbar">Navbar</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="ui-offcanvas.html" class="menu-link">
              <div data-i18n="Offcanvas">Offcanvas</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="ui-pagination-breadcrumbs.html" class="menu-link">
              <div data-i18n="Pagination & Breadcrumbs">Pagination &amp; Breadcrumbs</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="ui-progress.html" class="menu-link">
              <div data-i18n="Progress">Progress</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="ui-spinners.html" class="menu-link">
              <div data-i18n="Spinners">Spinners</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="ui-tabs-pills.html" class="menu-link">
              <div data-i18n="Tabs & Pills">Tabs &amp; Pills</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="ui-toasts.html" class="menu-link">
              <div data-i18n="Toasts">Toasts</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="ui-tooltips-popovers.html" class="menu-link">
              <div data-i18n="Tooltips & Popovers">Tooltips &amp; Popovers</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="ui-typography.html" class="menu-link">
              <div data-i18n="Typography">Typography</div>
            </a>
          </li>
        </ul>
      </li>

      <!-- Extended components -->
      <li class="menu-item">
        <a href="javascript:void(0)" class="menu-link menu-toggle">
          <i class="menu-icon icon-base bx bx-copy"></i>
          <div data-i18n="Extended UI">Extended UI</div>
        </a>
        <ul class="menu-sub">
          <li class="menu-item">
            <a href="extended-ui-avatar.html" class="menu-link">
              <div data-i18n="Avatar">Avatar</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="extended-ui-blockui.html" class="menu-link">
              <div data-i18n="BlockUI">BlockUI</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="extended-ui-drag-and-drop.html" class="menu-link">
              <div data-i18n="Drag & Drop">Drag &amp; Drop</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="extended-ui-media-player.html" class="menu-link">
              <div data-i18n="Media Player">Media Player</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="extended-ui-perfect-scrollbar.html" class="menu-link">
              <div data-i18n="Perfect Scrollbar">Perfect Scrollbar</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="extended-ui-star-ratings.html" class="menu-link">
              <div data-i18n="Star Ratings">Star Ratings</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="extended-ui-sweetalert2.html" class="menu-link">
              <div data-i18n="SweetAlert2">SweetAlert2</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="extended-ui-text-divider.html" class="menu-link">
              <div data-i18n="Text Divider">Text Divider</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="javascript:void(0);" class="menu-link menu-toggle">
              <div data-i18n="Timeline">Timeline</div>
            </a>
            <ul class="menu-sub">
              <li class="menu-item">
                <a href="extended-ui-timeline-basic.html" class="menu-link">
                  <div data-i18n="Basic">Basic</div>
                </a>
              </li>
              <li class="menu-item">
                <a href="extended-ui-timeline-fullscreen.html" class="menu-link">
                  <div data-i18n="Fullscreen">Fullscreen</div>
                </a>
              </li>
            </ul>
          </li>
          <li class="menu-item">
            <a href="extended-ui-tour.html" class="menu-link">
              <div data-i18n="Tour">Tour</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="extended-ui-treeview.html" class="menu-link">
              <div data-i18n="Treeview">Treeview</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="extended-ui-misc.html" class="menu-link">
              <div data-i18n="Miscellaneous">Miscellaneous</div>
            </a>
          </li>
        </ul>
      </li>

      <!-- Icons -->
      <li class="menu-item">
        <a href="javascript:void(0)" class="menu-link menu-toggle">
          <i class="menu-icon icon-base bx bx-crown"></i>
          <div data-i18n="Icons">Icons</div>
        </a>
        <ul class="menu-sub">
          <li class="menu-item">
            <a href="icons-boxicons.html" class="menu-link">
              <div data-i18n="Boxicons">Boxicons</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="icons-font-awesome.html" class="menu-link">
              <div data-i18n="Font Awesome">Font Awesome</div>
            </a>
          </li>
        </ul>
      </li>

      <!-- Forms & Tables -->
      <li class="menu-header small">
        <span class="menu-header-text" data-i18n="Forms & Tables">Forms &amp; Tables</span>
      </li>
      <!-- Forms -->
      <li class="menu-item {{ request()->routeIs('admin.forms.gradesheet', 'admin.forms.easy-login') ? 'active open' : '' }}">
        <a href="javascript:void(0);" class="menu-link menu-toggle">
          <i class="menu-icon icon-base bx bx-detail"></i>
          <div data-i18n="Form Elements">Form Elements</div>
        </a>
        <ul class="menu-sub">
          @if ($isAdminUser)
            <li class="menu-item {{ request()->routeIs('admin.forms.gradesheet') ? 'active' : '' }}">
              <a href="{{ route('admin.forms.gradesheet') }}" class="menu-link">
                <div>Grade Sheet PDF</div>
              </a>
            </li>
            <li class="menu-item {{ request()->routeIs('admin.forms.easy-login') ? 'active' : '' }}">
              <a href="{{ route('admin.forms.easy-login') }}" class="menu-link">
                <div>Easy Login</div>
              </a>
            </li>
          @endif
          <li class="menu-item">
            <a href="forms-basic-inputs.html" class="menu-link">
              <div data-i18n="Basic Inputs">Basic Inputs</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="forms-input-groups.html" class="menu-link">
              <div data-i18n="Input groups">Input groups</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="forms-custom-options.html" class="menu-link">
              <div data-i18n="Custom Options">Custom Options</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="forms-editors.html" class="menu-link">
              <div data-i18n="Editors">Editors</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="forms-file-upload.html" class="menu-link">
              <div data-i18n="File Upload">File Upload</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="forms-pickers.html" class="menu-link">
              <div data-i18n="Pickers">Pickers</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="forms-selects.html" class="menu-link">
              <div data-i18n="Select & Tags">Select &amp; Tags</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="forms-sliders.html" class="menu-link">
              <div data-i18n="Sliders">Sliders</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="forms-switches.html" class="menu-link">
              <div data-i18n="Switches">Switches</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="forms-extras.html" class="menu-link">
              <div data-i18n="Extras">Extras</div>
            </a>
          </li>
        </ul>
      </li>
      <li class="menu-item">
        <a href="javascript:void(0);" class="menu-link menu-toggle">
          <i class="menu-icon icon-base bx bx-detail"></i>
          <div data-i18n="Form Layouts">Form Layouts</div>
        </a>
        <ul class="menu-sub">
          <li class="menu-item">
            <a href="form-layouts-vertical.html" class="menu-link">
              <div data-i18n="Vertical Form">Vertical Form</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="form-layouts-horizontal.html" class="menu-link">
              <div data-i18n="Horizontal Form">Horizontal Form</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="form-layouts-sticky.html" class="menu-link">
              <div data-i18n="Sticky Actions">Sticky Actions</div>
            </a>
          </li>
        </ul>
      </li>
      <li class="menu-item">
        <a href="javascript:void(0);" class="menu-link menu-toggle">
          <i class="menu-icon icon-base bx bx-carousel"></i>
          <div data-i18n="Form Wizard">Form Wizard</div>
        </a>
        <ul class="menu-sub">
          <li class="menu-item">
            <a href="form-wizard-numbered.html" class="menu-link">
              <div data-i18n="Numbered">Numbered</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="form-wizard-icons.html" class="menu-link">
              <div data-i18n="Icons">Icons</div>
            </a>
          </li>
        </ul>
      </li>
      <li class="menu-item">
        <a href="form-validation.html" class="menu-link">
          <i class="menu-icon icon-base bx bx-list-check"></i>
          <div data-i18n="Form Validation">Form Validation</div>
        </a>
      </li>
      <!-- Tables -->
      <li class="menu-item">
        <a href="tables-basic.html" class="menu-link">
          <i class="menu-icon icon-base bx bx-table"></i>
          <div data-i18n="Tables">Tables</div>
        </a>
      </li>
      <li class="menu-item">
        <a href="javascript:void(0);" class="menu-link menu-toggle">
          <i class="menu-icon icon-base bx bx-grid"></i>
          <div data-i18n="Datatables">Datatables</div>
        </a>
        <ul class="menu-sub">
          <li class="menu-item">
            <a href="tables-datatables-basic.html" class="menu-link">
              <div data-i18n="Basic">Basic</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="tables-datatables-advanced.html" class="menu-link">
              <div data-i18n="Advanced">Advanced</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="tables-datatables-extensions.html" class="menu-link">
              <div data-i18n="Extensions">Extensions</div>
            </a>
          </li>
        </ul>
      </li>

      <!-- Charts & Maps -->
      <li class="menu-header small">
        <span class="menu-header-text" data-i18n="Charts & Maps">Charts &amp; Maps</span>
      </li>
      <li class="menu-item">
        <a href="javascript:void(0);" class="menu-link menu-toggle">
          <i class="menu-icon icon-base bx bx-chart"></i>
          <div data-i18n="Charts">Charts</div>
        </a>
        <ul class="menu-sub">
          <li class="menu-item">
            <a href="charts-apex.html" class="menu-link">
              <div data-i18n="Apex Charts">Apex Charts</div>
            </a>
          </li>
          <li class="menu-item">
            <a href="charts-chartjs.html" class="menu-link">
              <div data-i18n="ChartJS">ChartJS</div>
            </a>
          </li>
        </ul>
      </li>
      <li class="menu-item">
        <a href="maps-leaflet.html" class="menu-link">
          <i class="menu-icon icon-base bx bx-map-alt"></i>
          <div data-i18n="Leaflet Maps">Leaflet Maps</div>
        </a>
      </li>

      <!-- Misc -->
      <li class="menu-header small">
        <span class="menu-header-text" data-i18n="Misc">Misc</span>
      </li>

      <!-- Multi Level Menu -->
      <li class="menu-item">
        <a href="javascript:void(0)" class="menu-link menu-toggle">
          <i class="menu-icon icon-base bx bx-grid"></i>
          <div data-i18n="Multi Level">Multi Level</div>
        </a>
        <ul class="menu-sub">
          <li class="menu-item">
            <a href="javascript:void(0)" class="menu-link menu-toggle">
              <div data-i18n="Level 2">Level 2</div>
            </a>
            <ul class="menu-sub">
              <li class="menu-item">
                <a href="javascript:void(0)" class="menu-link">
                  <div data-i18n="Level 3">Level 3</div>
                </a>
              </li>
            </ul>
          </li>
        </ul>
      </li>
      <li class="menu-item">
        <a href="https://themeselection.com/support/" target="_blank" class="menu-link">
          <i class="menu-icon icon-base bx bx-support"></i>
          <div data-i18n="Support">Support</div>
        </a>
      </li>
      <li class="menu-item">
        <a href="#" target="_blank" class="menu-link">
          <i class="menu-icon icon-base bx bx-file"></i>
          <div data-i18n="Documentation">Documentation</div>
        </a>
      </li>
      @if ($authUser)
        <li class="menu-item">
          <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="menu-link w-100 border-0 bg-transparent text-start">
              <span class="app-shell-sidebar-dusk">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/thumbs-up/icons/icons8-logout-rounded-up.png',
                  'alt' => 'Logout',
                  'classes' => 'menu-icon app-shell-icon--admin-menu',
                ])
              </span>
              <div>Logout</div>
            </button>
          </form>
        </li>
      @endif
    </ul>
    
  
</aside>
