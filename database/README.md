# Operation veritabanı

Bu şema MySQL 5.7+ ve güncel MariaDB sürümleri için hazırlanmıştır. Boş veritabanında yalnızca `schema.sql` çalıştırılır; tablolar, roller, yetkiler, uçuş tipleri ve başlangıç süreçleri aynı dosyadadır.

Public kayıt akışı yoktur. Şema kurulduktan sonra ilk ve tek global admin `scripts/create_admin.php` ile oluşturulur. Admin rolü `global`, supervisor rolü bir veya daha fazla `airline` kapsamı, operation rolü ise `assigned` kapsamı ile atanır.

Tarih/saat değerleri uygulamanın mevcut çalışma bölgesiyle uyumlu olarak `Europe/Istanbul` / `+03:00` kabul edilir. Excel kolon eşlemesi gerçek gbeyan örneği görüldükten sonra sabitlenecektir.

Uygulama PHP 8.0+, `mysqli/mysqlnd`, `ZipArchive` ve `SimpleXML` gerektirir. CSV importunda ZipArchive gerekmez.

Kurulum sırası:

1. `database/schema.sql` dosyasını boş veritabanına aktarın.
2. Sunucuda/yerelde `php scripts/create_admin.php` çalıştırarak ilk admini oluşturun.
3. Admin hesabıyla giriş yapıp diğer ICAO kodlarını, kullanıcıları ve kapsamları arayüzden ekleyin.

Excel/CSV akışı yükleme → SQL staging önizlemesi → tek ekranda satır bazlı düzeltme/silme → yetkili son onay → `flights` tablosuna aktarım şeklindedir. gbeyan ile aynı sabit yerleşim kullanılır: ilk çalışma sayfasının A-Q kolonlarının tamamı okunur ve önizlemede Excel sırasıyla gösterilir. Başlık satırı `A/C`, `GELİŞ`, `GİDİŞ`, `PP` değerleriyle bulunur. A-D uçuş ve park, E-M zaman ve rota, N-Q uçak tipi ve tescil alanları olarak eşlenir. H geliş origin, I STA, J STD ve K gidiş destination alanıdır; arrival destination ve departure origin istasyon kodu `AYT` olarak tamamlanır. Uçuş tarihi önce üstteki ilk beş A hücresinden, bulunamazsa dosya adından alınır.

10 MB uygulama limitini kullanmak için PHP ayarlarında `upload_max_filesize` en az `10M`, `post_max_size` ise en az `11M` olmalıdır. Düzeltme ekranı bütün satırları tek görünümde listeler; her satır kendi formuyla kaydedildiği için shared-hosting `max_input_vars` sınırına takılmaz.
