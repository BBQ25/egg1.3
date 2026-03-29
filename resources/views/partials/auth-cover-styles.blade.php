<style>
  .auth-app-shell {
    min-height: 100vh;
    background:
      radial-gradient(circle at top left, rgba(255, 190, 92, 0.18), transparent 28%),
      radial-gradient(circle at bottom right, rgba(105, 108, 255, 0.16), transparent 34%),
      linear-gradient(180deg, #f8fbff 0%, #f3f6fb 100%);
  }

  .authentication-cover {
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
  }

  .authentication-cover .authentication-inner {
    max-width: 1280px;
    margin: 0 auto;
    overflow: hidden;
    border: 1px solid rgba(67, 89, 113, 0.12);
    border-radius: 1.75rem;
    background: rgba(255, 255, 255, 0.92);
    box-shadow: 0 1.5rem 3.5rem rgba(67, 89, 113, 0.12);
    backdrop-filter: blur(10px);
  }

  .auth-cover-brand {
    top: 1.5rem;
    left: 1.5rem;
  }

  .auth-cover-visual {
    position: relative;
    min-height: 100%;
    background:
      radial-gradient(circle at top right, rgba(105, 108, 255, 0.16), transparent 36%),
      linear-gradient(145deg, #fff7ea 0%, #f9fbff 48%, #eef4ff 100%);
  }

  .auth-cover-visual::after {
    content: "";
    position: absolute;
    right: -5rem;
    bottom: -5rem;
    width: 16rem;
    height: 16rem;
    border-radius: 999px;
    background: radial-gradient(circle, rgba(105, 108, 255, 0.16), transparent 68%);
    pointer-events: none;
  }

  .auth-cover-visual-stack {
    display: grid;
    gap: 2rem;
    align-items: center;
    width: 100%;
    min-height: 100%;
  }

  .auth-cover-copy {
    max-width: 30rem;
  }

  .auth-cover-copy h2 {
    margin: 0 0 0.85rem;
    font-size: clamp(2rem, 1.55rem + 1vw, 2.85rem);
    line-height: 1.02;
    letter-spacing: -0.03em;
    color: #243448;
  }

  .auth-cover-copy p {
    margin: 0;
    font-size: 1rem;
    line-height: 1.6;
    color: #6b7b8c;
  }

  .auth-cover-illustration {
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 18rem;
  }

  .auth-cover-image {
    width: min(100%, 42rem);
    max-height: 28rem;
    object-fit: contain;
    filter: drop-shadow(0 1rem 2rem rgba(67, 89, 113, 0.16));
  }

  .auth-cover-panel {
    justify-content: center;
    background: rgba(255, 255, 255, 0.94);
    min-height: 100%;
  }

  .auth-cover-form-shell {
    width: min(100%, 27rem);
  }

  .auth-cover-form-shell--wide {
    width: min(100%, 58rem);
  }

  @media (max-width: 991.98px) {
    .authentication-cover {
      padding: 0;
    }

    .authentication-cover .authentication-inner {
      max-width: none;
      min-height: 100vh;
      border: 0;
      border-radius: 0;
      box-shadow: none;
      backdrop-filter: none;
    }

    .auth-cover-panel {
      background: #fff;
    }

    .auth-cover-brand {
      top: 1rem;
      left: 1rem;
    }
  }

  @media (max-width: 767.98px) {
    .auth-cover-form-shell,
    .auth-cover-form-shell--wide {
      width: 100%;
    }
  }
</style>
