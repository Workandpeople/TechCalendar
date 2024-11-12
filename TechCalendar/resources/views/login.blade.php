<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <!-- CSS only -->
    <link href="{{ asset('css/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <title>Login</title>
    <style>
        .password-wrapper {
            position: relative;
        }
        .toggle-password {
            position: absolute;
            top: 50%;
            right: 1rem;
            transform: translateY(-50%);
            cursor: pointer;
        }
        .toggle-password img {
            width: 20px;
            height: 20px;
            opacity: 0.6;
        }
        .toggle-password img:hover {
            opacity: 1;
        }
    </style>
</head>
<body class="bg-light py-3 py-md-5">
    <section>
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-12 col-sm-10 col-md-8 col-lg-6 col-xl-5 col-xxl-4">
                    <div class="card border border-light-subtle rounded-3 shadow-sm">
                        <div class="card-body p-3 p-md-4 p-xl-5">
                            <div class="text-center mb-3">
                                <a href="#!">
                                    <img src="{{ asset('assets/banniere.png') }}" alt="BootstrapBrain Logo" width="100%" height="auto">
                                </a>
                            </div>
                            <h2 class="fs-6 fw-normal text-center text-secondary mb-4">Connectez-vous à votre compte</h2>
                            
                            <!-- Affichage des erreurs de connexion -->
                            @if ($errors->any())
                                <div class="alert alert-danger">
                                    <ul class="mb-0">
                                        @foreach ($errors->all() as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                            
                            <form action="{{ route('login.submit') }}" method="POST">
                                @csrf
                                <div class="row gy-2 overflow-hidden">
                                    <div class="col-12">
                                        <div class="form-floating mb-3">
                                            <input type="email" class="form-control" name="email" id="email" placeholder="name@example.com" required>
                                            <label for="email" class="form-label">Email</label>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="form-floating mb-3 password-wrapper">
                                            <input type="password" class="form-control" name="password" id="password" placeholder="Password" required>
                                            <label for="password" class="form-label">Password</label>
                                            <span class="toggle-password" onclick="togglePassword()">
                                                <img src="{{ asset('assets/eye.png') }}" alt="Show Password">
                                            </span>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="d-flex gap-2 justify-content-between">
                                            <a href="#!" class="link-primary text-decoration-none">Mot de passe oublié?</a>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="d-grid my-3">
                                            <button class="btn btn-primary btn-lg" type="submit">Connexion</button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
        }
    </script>
</body>
</html>