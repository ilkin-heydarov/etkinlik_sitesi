<?php
session_start();

// Kullanıcı oturum kontrolü
if (!isset($_SESSION['kullanici_id'])) {
    header("Location: pages/login.php");
    exit();
}

// Veritabanı bağlantısını dahil et
require_once 'database/db.php';

// Kullanıcı bilgilerini al
$kullanici_id = $_SESSION['kullanici_id'];
$sql = "SELECT * FROM kullanicilar WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $kullanici_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Kullanıcının ilgi alanlarını al
$interests_sql = "SELECT interest FROM user_interests WHERE user_id = ?";
$stmt = $conn->prepare($interests_sql);
$stmt->bind_param("i", $kullanici_id);
$stmt->execute();
$interests_result = $stmt->get_result();
$interests = [];
while ($row = $interests_result->fetch_assoc()) {
    $interests[] = $row['interest'];
}
$stmt->close();

// API'den etkinlikleri çekme
function getEvents() {
    // Dinamik URL oluştur (dosya sistemindeki gerçek yolu kullanarak)
    $api_url = $_SERVER['REQUEST_SCHEME'] . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . "/api/events.php";
    
    // API çağrısını yap
    $response = @file_get_contents($api_url);
    
    // API çağrısı başarısız olursa, doğrudan veritabanından çeksin
    if ($response === false) {
        global $conn;
        $events_sql = "SELECT * FROM events ORDER BY date ASC";
        $stmt = $conn->prepare($events_sql);
        $stmt->execute();
        $result = $stmt->get_result();
        $events = [];
        while ($row = $result->fetch_assoc()) {
            $events[] = $row;
        }
        $stmt->close();
        return $events;
    }
    
    // API yanıtını decode et
    return json_decode($response, true);
}

// Hava durumu API'sinden bilgi çekme
/**
 * OpenWeatherMap API kullanarak belirli bir tarih için hava durumu tahminini çeken fonksiyon
 * 
 * @param string $city Hava durumu alınacak şehir adı
 * @param string $date Hava durumu alınacak tarih (Y-m-d formatında)
 * @return array Hava durumu bilgilerini içeren dizi
 */
function getWeatherForecast($city, $date = null) {
    // API anahtarı
    $apiKey = "9477fcb6684a2804a49139e20c539b41";
    
    // Tarih belirtilmişse ve gelecekteki bir tarihse forecast API kullan
    $useCurrentWeather = true;
    $daysFromNow = 0;
    
    if ($date) {
        $eventDate = strtotime($date);
        $today = strtotime(date('Y-m-d'));
        
        if ($eventDate > $today) {
            $useCurrentWeather = false;
            $daysFromNow = round(($eventDate - $today) / (60 * 60 * 24));
            
            // OpenWeatherMap 5 günlük tahmin yapabilir
            if ($daysFromNow > 5) {
                // 5 günden fazla ise tahmin edilemeyen gün olarak işaretleyelim
                return [
                    "condition" => "unknown",
                    "temperature" => "--",
                    "humidity" => "--",
                    "wind_speed" => "--",
                    "city_name" => $city,
                    "country" => "TR",
                    "description" => "Ücretsiz API 5 günlük veri sunuyor",
                    "icon" => "https://openweathermap.org/img/wn/03d@2x.png", // Karışık bulutlu ikon
                    "from_api" => true,
                    "forecast_date" => date('d.m.Y', $eventDate)
                ];
            }
        }
    }
    
    // Şu anki hava durumu veya 5 günden kısa tahmin için
    if ($useCurrentWeather) {
        $apiUrl = "https://api.openweathermap.org/data/2.5/weather?q=" . urlencode($city) . "&units=metric&lang=tr&appid=" . $apiKey;
    } else {
        // 5 günlük tahmin API'sini kullan
        $apiUrl = "https://api.openweathermap.org/data/2.5/forecast?q=" . urlencode($city) . "&units=metric&lang=tr&appid=" . $apiKey;
    }
    
    // CURL ile API isteği gönder
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Zaman aşımını artırma
    $response = curl_exec($ch);
    $curl_info = curl_getinfo($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    // Hata ayıklama için log oluştur
    error_log("Weather API request for $city: Status code: " . $curl_info['http_code'] . ", Response: " . substr($response, 0, 200));
    if ($curl_error) {
        error_log("Weather API CURL error: " . $curl_error);
    }
    
    // Yanıt başarılı ise
    if ($curl_info['http_code'] == 200 && !empty($response)) {
        // JSON yanıtını diziye dönüştür
        $data = json_decode($response, true);
        
        if ($useCurrentWeather) {
            // Anlık hava durumu API yanıtı geçerli mi kontrol et
            if (isset($data['main']) && isset($data['weather'][0])) {
                // Hava durumu bilgilerini döndür
                $weatherDescription = $data['weather'][0]['description'];
                $weatherMain = $data['weather'][0]['main'];
                
                // Önce API'den gelen Türkçe açıklamayı kullan, yoksa çeviri yap
                $result = [
                    "condition" => strtolower($weatherMain),
                    "temperature" => round($data['main']['temp']),
                    "humidity" => $data['main']['humidity'],
                    "wind_speed" => $data['wind']['speed'],
                    "city_name" => $data['name'],
                    "country" => $data['sys']['country'],
                    "description" => $weatherDescription,
                    "icon" => "https://openweathermap.org/img/wn/" . $data['weather'][0]['icon'] . "@2x.png",
                    "from_api" => true
                ];
                
                if ($date) {
                    $result["forecast_date"] = date('d.m.Y', strtotime($date));
                } else {
                    $result["forecast_date"] = date('d.m.Y');
                }
                
                return $result;
            }
        } else {
            // 5 günlük tahmin API yanıtı
            if (isset($data['list']) && count($data['list']) > 0) {
                // Etkinlik tarihine en yakın tahmin indeksini bul
                $targetIndex = min($daysFromNow * 8, count($data['list']) - 1); // Günde 8 tahmin var (3 saatte bir)
                
                $forecast = $data['list'][$targetIndex];
                $weatherDescription = $forecast['weather'][0]['description'];
                $weatherMain = $forecast['weather'][0]['main'];
                
                return [
                    "condition" => strtolower($weatherMain),
                    "temperature" => round($forecast['main']['temp']),
                    "humidity" => $forecast['main']['humidity'],
                    "wind_speed" => $forecast['wind']['speed'],
                    "city_name" => $data['city']['name'],
                    "country" => $data['city']['country'],
                    "description" => $weatherDescription,
                    "icon" => "https://openweathermap.org/img/wn/" . $forecast['weather'][0]['icon'] . "@2x.png",
                    "from_api" => true,
                    "forecast_date" => date('d.m.Y', $eventDate)
                ];
            }
        }
    }
    
    // Geçici hata durumunda önceki API sonuçlarını önbellekten kontrol etme
    $cache_key = $date ? md5($city . '_' . $date) : md5($city);
    $cache_file = sys_get_temp_dir() . '/weather_cache_' . $cache_key . '.json';
    if (file_exists($cache_file) && (time() - filemtime($cache_file) < 3600)) { // 1 saat geçerli
        $cached_data = json_decode(file_get_contents($cache_file), true);
        if ($cached_data) {
            error_log("Using cached weather data for $city");
            return $cached_data;
        }
    }
    
    // Tüm API çağrı denemeleri başarısız olduğunda minimal varsayılan veri döndür
    error_log("All API calls failed. Using minimal fallback data for $city");
    $result = [
        "condition" => "unknown", 
        "temperature" => 20, 
        "humidity" => 60, 
        "wind_speed" => 4.0,
        "city_name" => $city, 
        "country" => "TR", 
        "description" => "API bağlantısı bekliyor...",
        "icon" => "https://openweathermap.org/img/wn/03d@2x.png", // Karışık bulutlu ikon
        "from_api" => false
    ];
    
    if ($date) {
        $result["forecast_date"] = date('d.m.Y', strtotime($date));
    } else {
        $result["forecast_date"] = date('d.m.Y');
    }
    
    return $result;
}

/**
 * NOT: Bu fonksiyona artık gerek yok, çünkü API zaten Türkçe sonuç dönüyor (lang=tr parametresi sayesinde)
 * Ancak API'nin düzgün çalışmaması durumunda yedek olarak tutulabilir
 */
function translateWeatherCondition($condition) {
    $translations = [
        'Clear' => 'açık',
        'Clouds' => 'bulutlu',
        'Rain' => 'yağmurlu',
        'Drizzle' => 'çiseleyen',
        'Thunderstorm' => 'fırtınalı',
        'Snow' => 'karlı',
        'Mist' => 'sisli',
        'Smoke' => 'dumanlı',
        'Haze' => 'puslu',
        'Dust' => 'tozlu',
        'Fog' => 'sisli',
        'Sand' => 'kumlu',
        'Ash' => 'küllü',
        'Squall' => 'sağanaklı',
        'Tornado' => 'kasırgalı'
    ];
    
    return isset($translations[$condition]) ? $translations[$condition] : 'bilinmeyen';
}

// Etkinlikleri tarihe göre sırala
$events = getEvents();
if (is_array($events)) {
    usort($events, function ($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });
} else {
    $events = []; // Eğer events null veya geçersizse boş dizi ata
}

// Sepete etkinlik ekleme işlemi
if (isset($_POST['add_to_cart'])) {
    $event_id = $_POST['event_id'];
    $ticket_type = $_POST['ticket_type'];
    
    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    
    $_SESSION['cart'][] = [
        'event_id' => $event_id,
        'ticket_type' => $ticket_type,
        'quantity' => 1
    ];
    
    // Etkinlik kontenjanını güncelle
    $update_quota = "UPDATE events SET available_tickets = available_tickets - 1 WHERE id = ?";
    $stmt = $conn->prepare($update_quota);
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $stmt->close();
    
    header("Location: index.php?cart_added=1");
    exit();
}

// Duyuruları al
$announcements_sql = "SELECT * FROM announcements WHERE is_active = 1 ORDER BY created_at DESC LIMIT 5";
$stmt = $conn->prepare($announcements_sql);
$stmt->execute();
$announcements_result = $stmt->get_result();
$announcements = [];
while ($row = $announcements_result->fetch_assoc()) {
    $announcements[] = $row;
}
$stmt->close();
?>


<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etkinlik Platformu</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Font Awesome CDN -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<link rel="icon" href="favicon.ico" type="image/x-icon">

    <style>
        .event-card {
            transition: transform 0.3s;
            margin-bottom: 20px;
        }
        .event-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .weather-suitable {
            color: green;
            font-weight: bold;
        }
        .weather-unsuitable {
            color: red;
            font-weight: bold;
        }
        .announcement {
            background-color: #f8f9fa;
            border-left: 4px solid #6a0dad;
            padding: 10px;
            margin-bottom: 10px;
        }
        .interest-match {
            border-left: 4px solid #198754;
        }
        .custom-navbar {
        background-color: #6a0dad !important;
    }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark custom-navbar">
        <div class="container">
            <a class="navbar-brand" href="index.php">Etkinlik Platformu</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php">Ana Sayfa</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="pages/user/profile.php">Profil</a>
                    </li>
                </ul>
                <div class="d-flex">
                    <a href="pages/cart.php" class="btn btn-outline-light me-2">
                        <i class="fas fa-shopping-cart"></i>
                        <?php echo isset($_SESSION['cart']) ? count($_SESSION['cart']) : 0; ?>
                    </a>
                    <a href="pages/logout.php" class="btn btn-outline-light">Çıkış Yap</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <?php if (isset($_GET['cart_added']) && $_GET['cart_added'] == 1): ?>
            <div class="alert alert-success alert-dismissible fade show">
                Etkinlik sepetinize eklendi!
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Ana İçerik -->
            <div class="col-lg-8">
                <h2 class="mb-4">Yaklaşan Etkinlikler</h2>
                
                <!-- Etkinlikler Filtreleme -->
                <div class="mb-4">
                    <form action="" method="get" class="row g-3">
                        <div class="col-md-4">
                            <select name="type" class="form-select">
                                <option value="">Tüm Türler</option>
                                <option value="konser">Konser</option>
                                <option value="tiyatro">Tiyatro</option>
                                <option value="festival">Festival</option>
                                <option value="spor">Spor</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <select name="city" class="form-select">
                                <option value="">Tüm Şehirler</option>
                                <option value="Adana">Adana</option>
<option value="Adıyaman">Adıyaman</option>
<option value="Afyonkarahisar">Afyonkarahisar</option>
<option value="Ağrı">Ağrı</option>
<option value="Amasya">Amasya</option>
<option value="Ankara">Ankara</option>
<option value="Antalya">Antalya</option>
<option value="Artvin">Artvin</option>
<option value="Aydın">Aydın</option>
<option value="Balıkesir">Balıkesir</option>
<option value="Bilecik">Bilecik</option>
<option value="Bingöl">Bingöl</option>
<option value="Bitlis">Bitlis</option>
<option value="Bolu">Bolu</option>
<option value="Burdur">Burdur</option>
<option value="Bursa">Bursa</option>
<option value="Çanakkale">Çanakkale</option>
<option value="Çankırı">Çankırı</option>
<option value="Çorum">Çorum</option>
<option value="Denizli">Denizli</option>
<option value="Diyarbakır">Diyarbakır</option>
<option value="Edirne">Edirne</option>
<option value="Elazığ">Elazığ</option>
<option value="Erzincan">Erzincan</option>
<option value="Erzurum">Erzurum</option>
<option value="Eskişehir">Eskişehir</option>
<option value="Gaziantep">Gaziantep</option>
<option value="Giresun">Giresun</option>
<option value="Gümüşhane">Gümüşhane</option>
<option value="Hakkari">Hakkari</option>
<option value="Hatay">Hatay</option>
<option value="Isparta">Isparta</option>
<option value="Mersin">Mersin</option>
<option value="İstanbul">İstanbul</option>
<option value="İzmir">İzmir</option>
<option value="Kars">Kars</option>
<option value="Kastamonu">Kastamonu</option>
<option value="Kayseri">Kayseri</option>
<option value="Kırklareli">Kırklareli</option>
<option value="Kırşehir">Kırşehir</option>
<option value="Kocaeli">Kocaeli</option>
<option value="Konya">Konya</option>
<option value="Kütahya">Kütahya</option>
<option value="Malatya">Malatya</option>
<option value="Manisa">Manisa</option>
<option value="Kahramanmaraş">Kahramanmaraş</option>
<option value="Mardin">Mardin</option>
<option value="Muğla">Muğla</option>
<option value="Muş">Muş</option>
<option value="Nevşehir">Nevşehir</option>
<option value="Niğde">Niğde</option>
<option value="Ordu">Ordu</option>
<option value="Rize">Rize</option>
<option value="Sakarya">Sakarya</option>
<option value="Samsun">Samsun</option>
<option value="Siirt">Siirt</option>
<option value="Sinop">Sinop</option>
<option value="Sivas">Sivas</option>
<option value="Tekirdağ">Tekirdağ</option>
<option value="Tokat">Tokat</option>
<option value="Trabzon">Trabzon</option>
<option value="Tunceli">Tunceli</option>
<option value="Şanlıurfa">Şanlıurfa</option>
<option value="Uşak">Uşak</option>
<option value="Van">Van</option>
<option value="Yozgat">Yozgat</option>
<option value="Zonguldak">Zonguldak</option>
<option value="Aksaray">Aksaray</option>
<option value="Bayburt">Bayburt</option>
<option value="Karaman">Karaman</option>
<option value="Kırıkkale">Kırıkkale</option>
<option value="Batman">Batman</option>
<option value="Şırnak">Şırnak</option>
<option value="Bartın">Bartın</option>
<option value="Ardahan">Ardahan</option>
<option value="Iğdır">Iğdır</option>
<option value="Yalova">Yalova</option>
<option value="Karabük">Karabük</option>
<option value="Kilis">Kilis</option>
<option value="Osmaniye">Osmaniye</option>
<option value="Düzce">Düzce</option>

                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary w-100">Filtrele</button>
                        </div>
                    </form>
                </div>

                <!-- Etkinlikler Listesi -->
                <div class="row">
                    <?php 
                    // Filtrele
                    $filtered_events = $events;
                    if (isset($_GET['type']) && !empty($_GET['type'])) {
                        $filtered_events = array_filter($filtered_events, function($event) {
                            return $event['type'] == $_GET['type'];
                        });
                    }
                    if (isset($_GET['city']) && !empty($_GET['city'])) {
                        $filtered_events = array_filter($filtered_events, function($event) {
                            return $event['city'] == $_GET['city'];
                        });
                    }
                    
                    if (count($filtered_events) == 0): ?>
                        <div class="col-12">
                            <p class="alert alert-info">Seçilen kriterlere uygun etkinlik bulunamadı.</p>
                        </div>
                    <?php else:
                        
                            foreach ($filtered_events as $event): 
                                // Etkinlik için hava durumunu kontrol et - ETKİNLİK TARİHİNİ DE GÖNDERİYORUZ
                                $weather = getWeatherForecast($event['city'], $event['date']);
                                
                                $is_weather_suitable = ($event['type'] == 'festival' || $event['type'] == 'outdoor' || $event['type'] == 'spor') 
                                    ? ($weather['condition'] != 'rainy' && $weather['condition'] != 'stormy') 
                                    : true;
                                
                                // Kullanıcının ilgi alanıyla eşleşiyor mu?
                                $is_interest_match = in_array($event['type'], $interests);
                    ?>
                    <div class="col-md-6">
                        <div class="card event-card <?php echo $is_interest_match ? 'interest-match' : ''; ?>">
                           
                            <div class="card-body">
                                <h5 class="card-title"><?php echo $event['title']; ?></h5>
                                <p class="card-text"><?php echo substr($event['description'], 0, 100); ?>...</p>
                                
                                <div class="d-flex justify-content-between mb-2">
                                    <span><i class="fas fa-calendar"></i> <?php echo date('d.m.Y', strtotime($event['date'])); ?></span>
                                    <span><i class="fas fa-map-marker-alt"></i> <?php echo $event['city']; ?></span>
                                </div>
                                
                                <div class="d-flex justify-content-between mb-3">
                                    <span>
                                        <i class="fas fa-tag"></i> <?php echo ucfirst($event['type']); ?>
                                    </span>
                                    <span>
                                        <i class="fas fa-users"></i> Kalan: <?php echo $event['available_tickets']; ?>
                                    </span>
                                </div>
                                
                                <div class="mb-3">
    <?php
    // Hava durumu ikonunu göster
    if (!empty($weather['icon'])) {
        // Hava durumu ikonuna göre belirleme
        switch ($weather['icon']) {
            case 'sun':
                $iconClass = 'fas fa-sun';  // Güneşli ikonu
                $iconAlt = 'Güneşli';
                break;
            case 'cloudy':
                $iconClass = 'fas fa-cloud';  // Bulutlu ikonu
                $iconAlt = 'Bulutlu';
                break;
            case 'rain':
                $iconClass = 'fas fa-cloud-rain';  // Yağmurlu ikonu
                $iconAlt = 'Yağmurlu';
                break;
            case 'storm':
                $iconClass = 'fas fa-bolt';  // Fırtınalı ikonu
                $iconAlt = 'Fırtınalı';
                break;
            default:
                $iconClass = 'fas fa-cloud-sun';  // Varsayılan ikonu (güneşli bulutlu)
                $iconAlt = 'Hava Durumu';
        }
        echo '<i class="' . $iconClass . '" style="font-size: 40px; margin-right: 10px;" aria-hidden="true"></i>';
    }
    ?>
    <span class="<?php echo $is_weather_suitable ? 'weather-suitable' : 'weather-unsuitable'; ?>">
        <?php
        // Hava durumu uygun mu, değilse uygun değil yazısı
        if ($is_weather_suitable) {
            echo '<i class="fas fa-check-circle" style="font-size: 20px; margin-right: 5px;"></i>Hava Durumu Uygun';
        } else {
            echo '<i class="fas fa-times-circle" style="font-size: 20px; margin-right: 5px;"></i>Hava Durumu Uygun Değil';
        }
        ?>
    </span>
    <div class="mt-1">
        <span class="text-muted">
            <?php echo ucfirst($weather['description']) . ', ' . $weather['temperature'] . '°C, Nem: %' . $weather['humidity']; ?>
        </span>
        <?php if($weather['from_api']): ?>
            <span class="badge bg-info ms-2">Güncel</span>
        <?php else: ?>
            <span class="badge bg-warning ms-2">Yükleniyor...</span>
        <?php endif; ?>
    </div>
</div>

                                
                                <form action="" method="post">
                                    <input type="hidden" name="event_id" value="<?php echo $event['id']; ?>">
                                    <div class="row g-2">
                                        <div class="col-md-7">
                                            <select name="ticket_type" class="form-select">
                                                <option value="standard">Standart (<?php echo $event['price']; ?> TL)</option>
                                                <option value="vip">VIP (<?php echo $event['price'] * 2; ?> TL)</option>
                                                <option value="student">Öğrenci (<?php echo $event['price'] * 0.5; ?> TL)</option>
                                            </select>
                                        </div>
                                        <div class="col-md-5">
                                            <button type="submit" name="add_to_cart" class="btn btn-primary w-100" <?php echo $event['available_tickets'] == 0 ? 'disabled' : ''; ?>>
                                                <i class="fas fa-cart-plus"></i> Sepete Ekle
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach;
                    endif; ?>
                </div>
            </div>
            
            <!-- Yan Panel -->
            <div class="col-lg-4">
                <!-- Kullanıcı Profil Özeti -->
                <div class="card mb-4">
                    <div class="card-body">
                    <h5 class="card-title">Hoş Geldin, <?php echo $user['ad'] . ' ' . $user['soyad']; ?></h5>
                        <p class="mb-2"><i class="fas fa-envelope"></i> <?php echo $user['email']; ?></p>
                        <p class="mb-0"><i class="fas fa-ticket-alt"></i> Sepetinizde <?php echo isset($_SESSION['cart']) ? count($_SESSION['cart']) : 0; ?> etkinlik var</p>
                    </div>
                </div>
                
                <!-- Duyurular -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-bullhorn"></i> Duyurular</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($announcements) == 0): ?>
                            <p>Aktif duyuru bulunmamaktadır.</p>
                        <?php else: 
                            foreach ($announcements as $announcement): ?>
                                <div class="announcement">
                                    <h6><?php echo $announcement['title']; ?></h6>
                                    <p><?php echo $announcement['content']; ?></p>
                                    <small class="text-muted"><?php echo date('d.m.Y', strtotime($announcement['created_at'])); ?></small>
                                </div>
                            <?php endforeach;
                        endif; ?>
                    </div>
                </div>
                
                <!-- İlgi Alanlarına Göre Öneriler -->
                <div class="card mb-4">
                    <div class="card-header bg-danger text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-star"></i> Size Özel</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($interests) == 0): ?>
                            <p>İlgi alanlarınızı profilinizden belirleyin, size özel etkinlikler önerelim!</p>
                        <?php else:
                            // İlgi alanlarına göre önerilen etkinlikleri bul
                            $recommended_events = array_filter($events, function($event) use ($interests) {
                                return in_array($event['type'], $interests);
                            });
                            
                            // En yakın 3 etkinliği göster
                            $recommended_events = array_slice($recommended_events, 0, 3);
                            
                            if (count($recommended_events) > 0):
                                foreach ($recommended_events as $event): ?>
                                    <div class="mb-3 pb-3 border-bottom">
                                        <h6><?php echo $event['title']; ?></h6>
                                        <div class="d-flex justify-content-between">
                                            <small><i class="fas fa-calendar"></i> <?php echo date('d.m.Y', strtotime($event['date'])); ?></small>
                                            <small><i class="fas fa-tag"></i> <?php echo ucfirst($event['type']); ?></small>
                                        </div>
                                        <div class="mt-2">
                                            <a href="event_detail.php?id=<?php echo $event['id']; ?>" onclick="event.preventDefault();" class="btn btn-sm btn-outline-success">Detaylar</a>
                                        </div>
                                    </div>
                                <?php endforeach;
                            else: ?>
                                <p>İlgi alanlarınıza uygun yaklaşan etkinlik bulunamadı.</p>
                            <?php endif;
                        endif; ?>
                    </div>
                </div>
                
                <!-- Hızlı Erişim -->
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-link"></i> Hızlı Erişim</h5>
                    </div>
                    <div class="card-body">
                        <div class="list-group">
                            <a href="pages/cart.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-shopping-cart"></i> Sepetim
                            </a>
                            <a href="past_tickets.php" onclick="event.preventDefault();" class="list-group-item list-group-item-action">
                                <i class="fas fa-ticket-alt"></i> Geçmiş Biletlerim
                            </a>
                            <a href="pages/user/profile.php" class="list-group-item list-group-item-action">
                                <i class="fas fa-user"></i> Profil Ayarlarım
                            </a>
                            <a href="help.php" onclick="event.preventDefault();" class="list-group-item list-group-item-action">
                                <i class="fas fa-question-circle"></i> Yardım
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <footer class="bg-dark text-white mt-5 py-4">
    <p style="text-align: center;">&copy; Designed by İlkin Heydarov <br> <a style="text-align: center; text-decoration: none; color: #fff;" href="https://www.ilkinheydarov.com/" target="_blank">www.ilkinheydarov.com</a></p>
    
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>