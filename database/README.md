# Operation veritabanı

Bu şema MySQL 5.7+ ve güncel MariaDB sürümleri için hazırlanmıştır. Boş veritabanında yalnızca `schema.sql` çalıştırılır; tablolar, roller, yetkiler, uçuş tipleri ve başlangıç süreçleri aynı dosyadadır.

Public kayıt akışı yoktur. Şema kurulduktan sonra ilk ve tek global admin `scripts/create_admin.php` ile oluşturulur. Admin rolü `global`, supervisor rolü bir veya daha fazla `airline` kapsamı, operation rolü ise `assigned` kapsamı ile atanır.

Tarih/saat değerleri uygulamanın mevcut çalışma bölgesiyle uyumlu olarak `Europe/Istanbul` / `+03:00` kabul edilir. Excel kolon eşlemesi gbeyan günlük uçuş dosyasının ilk sayfasındaki A-Q yerleşimine sabitlenmiştir.

Uygulama PHP 8.0+, `mysqli/mysqlnd`, `ZipArchive` ve `SimpleXML` gerektirir. CSV importunda ZipArchive gerekmez.

Kurulum sırası:

1. `database/schema.sql` dosyasını boş veritabanına aktarın.
2. Sunucuda/yerelde `php scripts/create_admin.php` çalıştırarak ilk admini oluşturun.
3. Admin hesabıyla giriş yapıp diğer ICAO kodlarını, kullanıcıları ve kapsamları arayüzden ekleyin.

Mevcut kurulumda Uçuş Zaman Çizelgesi özelliğini açmak için tam şemayı tekrar içe aktarmayın; yalnızca `database/migrations/001_flight_timeline.sql` dosyasını phpMyAdmin üzerinden bir kez çalıştırın. Bu migration süre tablolarını, timeline yetkilerini ve process ikon anahtarlarını ekler.

Excel/CSV akışı yükleme → geçici SQL staging önizlemesi → tek ekranda satır bazlı düzeltme/silme → yetkili son onay → `flights` tablosuna aktarım şeklindedir. Önizleme geçmiş olarak tutulmaz; başarılı aktarımda veya vazgeçildiğinde silinir, iki saatten eski tamamlanmamış önizlemeler otomatik temizlenir. gbeyan ile aynı sabit yerleşim kullanılır: ilk çalışma sayfasının A-Q kolonlarının tamamı okunur ve önizlemede Excel sırasıyla gösterilir. Başlık satırı `A/C`, `GELİŞ`, `GİDİŞ`, `PP` değerleriyle bulunur. A-D uçuş ve park, E-M zaman ve rota, N-Q uçak tipi ve tescil alanları olarak eşlenir. H geliş origin, I STA, J STD ve K gidiş destination alanıdır; arrival destination ve departure origin istasyon kodu `AYT` olarak tamamlanır. Uçuş tarihi önce üstteki ilk beş A hücresinden, bulunamazsa dosya adından alınır.

Her uçuşta yalnızca bir aktif sorumlu bulunur. Sorumlu kullanıcı operasyonu başlattığında durum `active` olur ve uçuş Genel Bakış ekranına girer; süreçlerin durumundan bağımsız olarak yalnızca aynı kullanıcı uçuşu tamamlayabilir ve süreçleri değiştirebilir. Yeni uçuşlar yalnızca Uçuş Ekle ekranından, Excel akışıyla veya aynı sayfadaki manuel formdan kaydedilir. Admin tamamlanmış bir uçuşu planlanan, devam ediyor veya iptal durumuna; yanlışlıkla başlatılmış devam eden bir uçuşu da planlanan durumuna geri alabilir. Bu geçişler süreç kayıtlarını değiştirmez. Devam eden uçuş planlanana alındığında mevcut atama kaldırılır, atama ekranı açılır ve yeni sorumlu seçilebilir. Uçuş silme işlemi kalıcıdır, yalnızca kısa audit kaydı bırakılır.

Uçuş Zaman Çizelgesi ETA/ETD değerlerini, bunlar yoksa STA/STD değerlerini kullanır. Firma ve uçak tipi süre kuralı bulunmazsa Arrival için 40, Departure için 60 dakikalık global varsayılan uygulanır. Admin süreleri aynı sayfadan yönetir; supervisor yalnız ICAO kapsamındaki uçuşları salt okunur olarak görür.

10 MB uygulama limitini kullanmak için PHP ayarlarında `upload_max_filesize` en az `10M`, `post_max_size` ise en az `11M` olmalıdır. Düzeltme ekranı bütün satırları tek görünümde listeler; her satır kendi formuyla kaydedildiği için shared-hosting `max_input_vars` sınırına takılmaz.
