
<?php
$pageTitle = "TerminalHub | Explore Palawan";
$pageDescription = "Discover Palawan and explore van and bus travel options between towns.";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b3d2a">
    <meta name="description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">

    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@500;600;700;800&family=Figtree:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --forest: #0b3d2a;
            --green: #168653;
            --green-dark: #0b5c39;
            --mint: #e2f3e8;
            --cream: #f7f6ef;
            --white: #ffffff;
            --ink: #14291e;
            --muted: #66756a;
            --line: #e3eae2;
            --display: "Bricolage Grotesque", sans-serif;
            --body: "Figtree", sans-serif;
            --pad: clamp(20px, 6vw, 88px);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            scroll-behavior: smooth;
            scroll-padding-top: 100px;
            -webkit-text-size-adjust: 100%;
        }

        body {
            font-family: var(--body);
            color: var(--ink);
            background: var(--cream);
            line-height: 1.6;
            overflow-x: hidden;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        a:focus-visible {
            outline: 3px solid #e9b927;
            outline-offset: 4px;
        }

        /* NAVIGATION */

        .navbar {
            position: sticky;
            top: 0;
            z-index: 100;
            min-height: 82px;
            padding: 15px var(--pad);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            background: rgba(255, 255, 255, 0.94);
            border-bottom: 1px solid var(--line);
            backdrop-filter: blur(16px);
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 11px;
            font-family: var(--display);
            font-size: 1.6rem;
            font-weight: 800;
            letter-spacing: -1.2px;
            white-space: nowrap;
        }

        .logo-mark {
            width: 44px;
            height: 44px;
            display: grid;
            place-items: center;
            border-radius: 15px 15px 15px 5px;
            background: linear-gradient(145deg, #209e65, #0b5c39);
            color: white;
            font-size: 1.35rem;
            box-shadow: 0 6px 16px #16865330;
        }

        .logo b {
            color: var(--green);
        }

        /* HERO */

        .hero {
            position: relative;
            min-height: min(760px, calc(100svh - 82px));
            display: flex;
            align-items: center;
            justify-content: center;
            isolation: isolate;
            overflow: hidden;
            padding: 85px var(--pad) 78px;
            background:
                radial-gradient(
                    ellipse at 50% 0%,
                    rgba(211, 238, 217, 0.85),
                    transparent 53%
                ),
                linear-gradient(180deg, #f7f8f0, #f7f6ef);
        }

        .decoration {
            position: absolute;
            z-index: -1;
            pointer-events: none;
        }

        .decoration-left {
            top: 17%;
            left: -115px;
            width: 300px;
            height: 300px;
            border: 1px solid #16865320;
            border-radius: 50%;
            box-shadow: 0 0 0 28px #16865308, 0 0 0 58px #16865306;
        }

        .decoration-right {
            right: -90px;
            bottom: -145px;
            width: 360px;
            height: 360px;
            border: 1px solid #16865325;
            border-radius: 50%;
            box-shadow: 0 0 0 28px #16865308, 0 0 0 58px #16865306;
        }

        .decoration-dot {
            top: 20%;
            right: 15%;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #8ec9a0;
            box-shadow: 28px 25px 0 -3px #16865345, -25px 36px 0 -4px #8ec9a055;
            animation: float 5s ease-in-out infinite;
        }

        .hero-copy {
            width: 100%;
            max-width: 930px;
            margin: 0 auto;
            text-align: center;
            animation: fadeUp 0.8s ease both;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 27px;
            padding: 9px 16px;
            border: 1px solid #d2e6d5;
            border-radius: 100px;
            background: rgba(255, 255, 255, 0.8);
            color: var(--green-dark);
            font-size: 0.77rem;
            font-weight: 800;
            letter-spacing: 0.09em;
        }

        .eyebrow-dot {
            width: 8px;
            height: 8px;
            flex: none;
            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 0 4px #16865317;
        }

        h1 {
            font-family: var(--display);
            font-size: clamp(3.5rem, 8.2vw, 7rem);
            font-weight: 800;
            line-height: 0.98;
            letter-spacing: -0.075em;
        }

        h1 span {
            position: relative;
            display: inline-block;
            color: var(--green);
        }

        h1 span::after {
            position: absolute;
            right: 3%;
            bottom: -8px;
            left: 3%;
            height: 5px;
            border-radius: 50%;
            background: #94cba2;
            content: "";
            transform: rotate(-1.5deg);
        }

        .hero-description {
            max-width: 590px;
            margin: 30px auto 0;
            color: var(--muted);
            font-size: clamp(1rem, 1.6vw, 1.12rem);
            line-height: 1.9;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 14px;
            margin-top: 35px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            min-height: 56px;
            padding: 0 25px;
            border: 1px solid transparent;
            border-radius: 13px;
            background: var(--green);
            color: white;
            font-size: 0.96rem;
            font-weight: 800;
            box-shadow: 0 8px 22px #16865325;
            transition: background 0.2s, transform 0.2s, box-shadow 0.2s;
        }

        .button:hover {
            background: var(--forest);
            transform: translateY(-3px);
            box-shadow: 0 12px 26px #0b3d2a25;
        }

        .button-arrow {
            display: grid;
            width: 25px;
            height: 25px;
            place-items: center;
            border-radius: 50%;
            background: #ffffff26;
            transition: transform 0.2s;
        }

        .button:hover .button-arrow {
            transform: translateX(3px);
        }

        .button-outline {
            border-color: #d7e0d6;
            background: rgba(255, 255, 255, 0.8);
            color: var(--ink);
            box-shadow: 0 5px 15px #14291e08;
        }

        .button-outline:hover {
            border-color: var(--green);
            background: white;
            color: var(--green-dark);
        }

        .hero-note {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 29px;
            color: var(--muted);
            font-size: 0.86rem;
        }

        .check {
            width: 23px;
            height: 23px;
            display: grid;
            flex: none;
            place-items: center;
            border-radius: 50%;
            background: var(--mint);
            color: var(--green-dark);
            font-size: 0.78rem;
            font-weight: 800;
        }

        /* FOOTER */

        .footer {
            padding: 25px var(--pad);
            border-top: 1px solid var(--line);
            background: #ffffffb8;
        }

        .footer-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            max-width: 1180px;
            margin: 0 auto;
        }

        .footer-brand {
            font-family: var(--display);
            font-size: 1rem;
            font-weight: 800;
        }

        .footer-brand span {
            color: var(--green);
        }

        .footer p,
        .footer-links a {
            color: var(--muted);
            font-size: 0.83rem;
        }

        .footer-links {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }

        .footer-links a:hover {
            color: var(--green);
        }

        /* ANIMATIONS */

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(18px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-12px); }
        }

        /* TABLET */

        @media (max-width: 900px) {
            .hero { min-height: 620px; }
        }

        /* MOBILE */

        @media (max-width: 600px) {
            .navbar {
                min-height: 72px;
                padding-top: 12px;
                padding-bottom: 12px;
            }

            .logo {
                gap: 8px;
                font-size: 1.32rem;
            }

            .logo-mark {
                width: 37px;
                height: 37px;
                border-radius: 12px 12px 12px 4px;
            }

            .hero {
                min-height: auto;
                padding: 85px 22px 75px;
            }

            .hero-copy { max-width: 480px; }

            .eyebrow {
                gap: 8px;
                margin-bottom: 25px;
                padding: 8px 11px;
                font-size: 0.66rem;
                letter-spacing: 0.055em;
            }

            h1 {
                font-size: clamp(3.15rem, 11.5vw, 4.6rem);
                line-height: 1.02;
            }

            h1 span::after {
                bottom: -5px;
                height: 3px;
            }

            .hero-description {
                margin-top: 24px;
                font-size: 0.98rem;
                line-height: 1.8;
            }

            .hero-actions {
                flex-direction: column;
                gap: 12px;
                margin-top: 29px;
            }

            .button {
                width: 100%;
                min-height: 53px;
            }

            .hero-note {
                align-items: flex-start;
                justify-content: center;
                max-width: 300px;
                margin: 25px auto 0;
                font-size: 0.82rem;
                text-align: left;
            }

            .decoration-left { top: 8%; left: -210px; }
            .decoration-right { right: -240px; }

            .decoration-dot {
                top: 10%;
                right: 12%;
                transform: scale(0.8);
            }

            .footer-inner {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .footer-links { gap: 16px; }
        }

        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }

            *,
            *::before,
            *::after {
                animation: none !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>

<body>

    <!-- NAVIGATION -->
    <header class="navbar">
        <a class="logo" href="index.php" aria-label="TerminalHub Home">
            <span class="logo-mark">T</span>
            <span>Terminal<b>Hub</b></span>
        </a>
    </header>

    <main>
        <!-- HERO SECTION -->
        <section class="hero">
            <div class="decoration decoration-left"></div>
            <div class="decoration decoration-right"></div>
            <div class="decoration decoration-dot"></div>

            <div class="hero-copy">
                <div class="eyebrow">
                    <span class="eyebrow-dot"></span>
                    YOUR PALAWAN JOURNEY STARTS HERE
                </div>

                <h1>
                    Move beyond.<br>
                    See <span>Palawan.</span>
                </h1>

                <p class="hero-description">
                    Discover the beauty of Palawan and explore van and
                    bus travel options between towns. Find your next
                    destination and start planning your journey with ease.
                </p>

                <!-- LOGIN AND REGISTER OPTIONS -->
                <div class="hero-actions">
                    <a class="button" href="login.php">
                        Log in
                        <span class="button-arrow" aria-hidden="true">→</span>
                    </a>

                    <a class="button button-outline" href="login.php?mode=register">
                        Register
                    </a>
                </div>

                <p class="hero-note">
                    <span class="check" aria-hidden="true">✓</span>
                    Designed for mobile devices and easy navigation
                </p>
            </div>
        </section>
    </main>

    <!-- FOOTER -->
    <footer class="footer">
        <div class="footer-inner">
            <a class="footer-brand" href="index.php">
                Terminal<span>Hub</span>
            </a>

            <p>
                © <?= date('Y') ?> TerminalHub. Explore Palawan with ease.
            </p>

            <div class="footer-links">
                <a href="about.php">About</a>
            </div>
        </div>
    </footer>
</body>
</html>
