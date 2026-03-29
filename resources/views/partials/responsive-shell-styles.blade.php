<style>
  html,
  body {
    max-width: 100%;
    overflow-x: hidden;
  }

  img,
  svg,
  video,
  canvas,
  iframe {
    max-width: 100%;
  }

  .table-responsive {
    -webkit-overflow-scrolling: touch;
  }

  .table-responsive > .table {
    margin-bottom: 0;
  }

  .layout-page,
  .content-wrapper,
  .container-xxl,
  .authentication-wrapper,
  .authentication-inner,
  .nms-shell,
  .nms-main {
    min-width: 0;
  }

  .authentication-cover .w-px-400 {
    width: min(100%, 400px) !important;
  }

  .authentication-cover .authentication-inner {
    min-height: 100vh;
  }

  .authentication-cover .authentication-bg {
    min-width: 0;
  }

  @media (max-width: 991.98px) {
    .layout-navbar {
      width: auto;
      max-width: calc(100% - 1.5rem);
      margin-inline: 0.75rem;
    }

    .layout-navbar .navbar-nav-right {
      align-items: flex-start !important;
      gap: 0.75rem;
    }

    .layout-navbar .navbar-nav-right > .d-flex.align-items-center.gap-3 {
      width: 100%;
      justify-content: space-between !important;
      align-items: flex-start !important;
      flex-wrap: wrap;
      gap: 0.75rem !important;
    }

    .container-xxl.container-p-y {
      padding-top: 1rem;
      padding-bottom: 1rem;
    }

    .card-footer {
      gap: 0.75rem;
    }

    .modal {
      padding-right: 0 !important;
    }

    .modal-dialog {
      max-width: calc(100% - 1.5rem);
      margin: 0.75rem;
    }

    .modal-footer {
      gap: 0.75rem;
    }

    .modal-footer > * {
      margin: 0 !important;
    }

    .authentication-cover .authentication-bg {
      padding: 1.5rem !important;
    }

    .geofence-card {
      margin: 1.5rem auto;
    }
  }

  @media (max-width: 767.98px) {
    .layout-navbar {
      max-width: calc(100% - 1rem);
      margin-inline: 0.5rem;
      border-radius: 1rem;
    }

    .container-xxl.container-p-y {
      padding-inline: 0.75rem;
    }

    .card-header,
    .card-body,
    .card-footer {
      padding-left: 1rem;
      padding-right: 1rem;
    }

    .card-footer {
      flex-direction: column;
      align-items: stretch !important;
    }

    .table-responsive table {
      min-width: 40rem;
    }

    .layout-menu .menu-link {
      white-space: normal;
      align-items: flex-start;
    }

    .layout-menu .menu-sub .menu-link {
      padding-right: 1rem;
    }

    .authentication-wrapper {
      padding-inline: 0;
    }

    .authentication-cover .auth-cover-brand {
      inset-inline-start: 1rem;
      top: 1rem;
    }

    .authentication-cover .authentication-bg {
      padding: 1rem !important;
    }

    .authentication-cover .card-body {
      padding: 1rem;
    }

    .geofence-page {
      padding: 1rem !important;
    }

    #restricted-geofence-map {
      min-height: 280px;
    }
  }
</style>
