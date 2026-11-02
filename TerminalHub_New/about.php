
<?php
$pageTitle = "About Us | TerminalHub";
$pageDescription = "Learn about TerminalHub, a travel-planning platform for exploring Palawan and discovering bus and van transportation options.";
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
        }

        body {
            font-family: var(--body);
            color: var(--ink);
            background: var(--cream);
            line-height: 1.7;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        .navbar {
            position: sticky;
            top: 0;
            z-index: 10;
            min-height: 82px;
            padding: 15px var(--pad);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            background: rgba(255, 255, 255, 0.96);
            border-bottom: 1px solid var(--line);
            backdrop-filter: blur(14px);
        }

        .logo {
            display: inline-flex;
            align-items: center;
            gap: 11px;
            font-family: var(--display);
            font-size: 1.6rem;
            font-weight: 800;
            letter-spacing: -1px;
        }

        .logo-mark {
            width: 44px;
            height: 44px;
            display: grid;
            place-items: center;
            border-radius: 15px 15px 15px 5px;
            background: linear-gradient(145deg, #209e65, #0b5c39);
            color: white;
        }

        .logo b {
            color: var(--green);
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 25px;
            font-size: 0.94rem;
            font-weight: 700;
        }

        .nav-links a:hover {
            color: var(--green);
        }

        .nav-button {
            padding: 11px 19px;
            border-radius: 11px;
            background: var(--green);
            color: white !important;
        }

        .hero {
            position: relative;
            overflow: hidden;
            padding: 100px var(--pad) 95px;
            background:
                radial-gradient(ellipse at 80% 15%, #d8eedb 0%, transparent 40%),
                linear-gradient(180deg, #f7f8f0, var(--cream));
        }

        .hero-inner {
            max-width: 1120px;
            margin: auto;
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            align-items: center;
            gap: 55px;
        }

        .eyebrow {
            display: inline-block;
            margin-bottom: 22px;
            padding: 8px 14px;
            border: 1px solid #d2e6d5;
            border-radius: 100px;
            background: white;
            color: var(--green-dark);
            font-size: 0.75rem;
            font-weight: 800;
            letter-spacing: 0.09em;
        }

        h1, h2, h3 {
            font-family: var(--display);
            line-height: 1.15;
            letter-spacing: -0.035em;
        }

        h1 {
            max-width: 650px;
            color: var(--forest);
            font-size: clamp(2.8rem, 6vw, 5rem);
            font-weight: 800;
        }

        h1 span {
            color: var(--green);
        }

        .hero-copy > p {
            max-width: 570px;
            margin-top: 24px;
            color: var(--muted);
            font-size: 1.08rem;
        }

        .hero-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 13px;
            margin-top: 30px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 51px;
            padding: 0 22px;
            border: 1px solid var(--green);
            border-radius: 12px;
            background: var(--green);
            color: white;
            font-weight: 800;
            transition: 0.2s ease;
        }

        .button:hover {
            transform: translateY(-2px);
            background: var(--forest);
        }

        .button-outline {
            border-color: var(--line);
            background: white;
            color: var(--forest);
        }

        .button-outline:hover {
            color: white;
        }

        .hero-visual {
            min-height: 350px;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border: 1px solid #d4e7d6;
            border-radius: 35px 35px 35px 8px;
            background:
                radial-gradient(circle at 75% 25%, #b8dfbd 0 8%, transparent 8.5%),
                radial-gradient(ellipse at 25% 90%, #168653 0 18%, transparent 18.5%),
                radial-gradient(ellipse at 70% 100%, #0b5c39 0 25%, transparent 25.5%),
                linear-gradient(150deg, #d8efdd, #9dcfa9 60%, #e4f3df);
        }

        .visual-circle {
            position: absolute;
            width: 240px;
            height: 240px;
            border: 1px solid #ffffffa8;
            border-radius: 50%;
            box-shadow: 0 0 0 25px #ffffff30, 0 0 0 50px #ffffff20;
        }

        .visual-card {
            position: relative;
            z-index: 1;
            width: min(78%, 290px);
            padding: 25px;
            border: 1px solid #ffffffb0;
            border-radius: 20px;
            background: #ffffffed;
            box-shadow: 0 20px 50px #0b3d2a20;
        }

        .visual-card .icon {
            font-size: 2rem;
        }

        .visual-card h3 {
            margin: 10px 0;
            color: var(--forest);
            font-size: 1.5rem;
        }

        .visual-card p {
            color: var(--muted);
            font-size: 0.92rem;
        }

        .section {
            padding: 85px var(--pad);
        }

        .section-inner {
            max-width: 1120px;
            margin: auto;
        }

        .section-heading {
            max-width: 720px;
            margin: 0 auto 45px;
            text-align: center;
        }

        .section-heading h2 {
            margin-bottom: 16px;
            color: var(--forest);
            font-size: clamp(2rem, 4vw, 3.1rem);
        }

        .section-heading p {
            color: var(--muted);
        }

        .purpose-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 22px;
        }

        .purpose-card {
            padding: 29px;
            border: 1px solid var(--line);
            border-radius: 20px;
            background: white;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .purpose-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px #0b3d2a0c;
        }

        .purpose-icon {
            width: 52px;
            height: 52px;
            display: grid;
            place-items: center;
            margin-bottom: 20px;
            border-radius: 16px;
            background: var(--mint);
            font-size: 1.5rem;
        }

        .purpose-card h3 {
            margin-bottom: 12px;
            color: var(--forest);
            font-size: 1.35rem;
        }

        .purpose-card p {
            color: var(--muted);
            font-size: 0.95rem;
        }

        .mission {
            background: var(--forest);
            color: white;
        }

        .mission-inner {
            max-width: 900px;
            margin: auto;
            text-align: center;
        }

        .mission .eyebrow {
            border-color: #ffffff30;
            background: #ffffff12;
            color: #bce9c8;
        }

        .mission h2 {
            margin-bottom: 20px;
            font-size: clamp(2rem, 4.5vw, 3.5rem);
        }

        .mission p {
            max-width: 730px;
            margin: auto;
            color: #d4e5d9;
            font-size: 1.05rem;
        }

        .vision {
            display: grid;
            grid-template-columns: 0.85fr 1.15fr;
            align-items: center;
            gap: 45px;
        }

        .vision-label {
            color: var(--green);
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.1em;
        }

        .vision h2 {
            margin: 12px 0 18px;
            color: var(--forest);
            font-size: clamp(2rem, 4vw, 3rem);
        }

        .vision p {
            color: var(--muted);
        }

        .vision-panel {
            padding: 35px;
            border: 1px solid #d4e7d6;
            border-radius: 25px;
            background: linear-gradient(145deg, white, var(--mint));
        }

        .vision-panel h3 {
            margin-bottom: 12px;
            color: var(--forest);
            font-size: 1.4rem;
        }

        .vision-panel p {
            color: var(--muted);
        }

        .cta {
            padding: 65px var(--pad);
            text-align: center;
            background: #e9f4e9;
        }

        .cta h2 {
            margin-bottom: 14px;
            color: var(--forest);
            font-size: clamp(2rem, 4vw, 3rem);
        }

        .cta p {
            max-width: 650px;
            margin: 0 auto 25px;
            color: var(--muted);
        }

        .footer {
            padding: 25px var(--pad);
            border-top: 1px solid var(--line);
            background: white;
        }

        .footer-inner {
            max-width: 1180px;
            margin: auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
        }

        .footer-brand {
            font-family: var(--display);
            font-weight: 800;
        }

        .footer-brand span {
            color: var(--green);
        }

        .footer p, .footer-links a {
            color: var(--muted);
            font-size: 0.83rem;
        }

        .footer-links {
            display: flex;
            flex-wrap: wrap;
            gap: 18px;
        }

        .footer-links a:hover {
            color: var(--green);
        }

        @media (max-width: 800px) {
            .hero-inner, .vision {
                grid-template-columns: 1fr;
            }

            .hero-inner {
                gap: 38px;
            }

            .hero-visual {
                min-height: 300px;
            }

            .purpose-grid {
                grid-template-columns: 1fr;
            }

            .section {
                padding-top: 65px;
                padding-bottom: 65px;
            }
        }

        @media (max-width: 600px) {
            .navbar {
                min-height: 72px;
                padding-top: 12px;
                padding-bottom: 12px;
            }

            .logo {
                gap: 8px;
                font-size: 1.3rem;
            }

            .logo-mark {
                width: 37px;
                height: 37px;
            }

            .nav-links {
                gap: 13px;
                font-size: 0.82rem;
            }

            .hero {
                padding-top: 65px;
                padding-bottom: 65px;
            }

            .hero-visual {
                min-height: 270px;
                border-radius: 25px 25px 25px 6px;
            }

            .purpose-card {
                padding: 25px;
            }

            .vision-panel {
                padding: 27px;
            }

            .footer-inner {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }

            *, *::before, *::after {
                animation: none !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>

<body>
    <header class="navbar">
        <a class="logo" href="index.php" aria-label="TerminalHub Home">
            <span class="logo-mark">T</span>
            <span>Terminal<b>Hub</b></span>
        </a>

        <!-- UPDATED NAVIGATION: ABOUT REMOVED -->
        <nav class="nav-links" aria-label="Main navigation">
            <a href="index.php">Home</a>
            <a class="nav-register" href="login.php?mode=register">Register</a>
            <a class="nav-button" href="login.php">Log in</a>
        </nav>
    </header>

    <main>
        <section class="hero" id="about">
            <div class="hero-inner">
                <div class="hero-copy">
                    <span class="eyebrow">GET TO KNOW TERMINALHUB</span>
                    <h1>Your Journey Around <span>Palawan</span> Starts Here.</h1>
                    <p>
                        TerminalHub is a web-based travel platform designed to help
                        travelers explore Palawan and discover bus and van transportation
                        options between destinations. We aim to make travel planning
                        more convenient by bringing useful travel information together
                        in one accessible place.
                    </p>

                    <div class="hero-buttons">
                        <a class="button" href="login.php?mode=register">Get Started →</a>
                        <a class="button button-outline" href="index.php">Back to Home</a>
                    </div>
                </div>

                <div class="hero-visual" role="img" aria-label="Illustration representing travel around Palawan">
                    <div class="visual-circle"></div>
                    <div class="visual-card">
                        <div class="icon">🌴</div>
                        <h3>Explore More.</h3>
                        <p>Discover destinations and explore transportation options for your Palawan journey.</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="section">
            <div class="section-inner">
                <div class="section-heading">
                    <span class="eyebrow">WHAT WE OFFER</span>
                    <h2>Designed to Help You Plan</h2>
                    <p>
                        Our platform focuses on making it easier to explore destinations
                        and find useful transportation information for your next trip.
                    </p>
                </div>

                <div class="purpose-grid">
                    <article class="purpose-card">
                        <div class="purpose-icon">📍</div>
                        <h3>Explore Destinations</h3>
                        <p>
                            Learn about places around Palawan and discover destinations
                            you may want to include in your travel plans.
                        </p>
                    </article>

                    <article class="purpose-card">
                        <div class="purpose-icon">🚌</div>
                        <h3>Transportation Options</h3>
                        <p>
                            Explore bus and van travel options between towns as part
                            of preparing for your journey.
                        </p>
                    </article>

                    <article class="purpose-card">
                        <div class="purpose-icon">🗓️</div>
                        <h3>Convenient Planning</h3>
                        <p>
                            Bring important parts of your travel planning together
                            through one accessible web platform.
                        </p>
                    </article>
                </div>
            </div>
        </section>

        <section class="section mission">
            <div class="mission-inner">
                <span class="eyebrow">OUR MISSION</span>
                <h2>Making Travel Planning More Convenient</h2>
                <p>
                    Our mission is to provide travelers with an accessible platform
                    for exploring Palawan and discovering transportation options.
                    We aim to help users prepare for their journeys with greater
                    convenience and a clearer view of their travel choices.
                </p>
            </div>
        </section>

        <section class="section">
            <div class="section-inner vision">
                <div>
                    <span class="vision-label">OUR VISION</span>
                    <h2>Connecting Travelers with Their Next Destination</h2>
                    <p>
                        We envision TerminalHub as a helpful travel-planning platform
                        that supports easier exploration of Palawan by making
                        destination and transportation information more accessible.
                    </p>
                </div>

                <div class="vision-panel">
                    <h3>Explore Palawan with Confidence</h3>
                    <p>
                        Whether you are preparing to visit a familiar town or planning
                        to discover somewhere new, TerminalHub is designed to help you
                        begin organizing your trip in one place.
                    </p>
                </div>
            </div>
        </section>

        <section class="cta">
            <h2>Ready to Explore Palawan?</h2>
            <p>
                Start planning your next journey and discover the transportation
                options available through TerminalHub.
            </p>
            <a class="button" href="login.php?mode=register">Get Started →</a>
        </section>
    </main>

    <footer class="footer">
        <div class="footer-inner">
            <a class="footer-brand" href="index.php">Terminal<span>Hub</span></a>

            <p>© <?= date('Y') ?> TerminalHub. Explore Palawan with ease.</p>

            <!-- UPDATED FOOTER: ABOUT REMOVED -->
            <div class="footer-links">
                <a href="index.php">Home</a>
                <a href="login.php?mode=register">Register</a>
            </div>
        </div>
    </footer>
</body>
</html>
