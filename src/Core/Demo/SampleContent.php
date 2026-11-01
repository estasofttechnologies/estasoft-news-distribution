<?php
/**
 * The words of the demo's sample articles.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Demo;

defined( 'ABSPATH' ) || exit;

/**
 * Four invented local stories, each written in English, Kurdish (Kurmanji, Latin script) and Arabic.
 *
 * The text is plain and neutral. It names no real person, brand or place: "the harbor", "the old bridge" and
 * "the island" are any harbor, bridge and island. These are sample posts a person reads and then removes, so
 * they are data, not interface text, and they do not go through the translation functions.
 *
 * Each story has three deliberate shapes that the Article check can find, one in one language:
 *
 * - `title_long`: a title over the 110 characters that read well in a feed.
 * - `alt_missing`: a featured image with no alt text.
 * - `empty_paragraph`: an empty paragraph block in the middle of the body.
 *
 * Every other article is clean on those three points.
 */
final class SampleContent {

	/**
	 * The title prefix every sample carries, so a person can tell a sample at a glance.
	 */
	public const PREFIX = 'Sample: ';

	/**
	 * The languages of the samples, in the order they are created.
	 *
	 * @return string[]
	 */
	public static function languages(): array {
		return array( 'en', 'ku', 'ar' );
	}

	/**
	 * The stories, in the order they are created. Offsets are minutes before now.
	 *
	 * `alt` is the picture's alt text, `flags` names the deliberate shape of one language's article, `color` is the picture's
	 * base color, and `body` is the paragraphs of each language.
	 *
	 * @return array<string, array{category: string, minutes: int, color: array<int, int>, alt: array<string, string>, flags: array<string, string>, titles: array<string, string>, body: array<string, string[]>}>
	 */
	public static function stories(): array {
		return array(
			'festival' => array(
				'category' => 'events',
				'minutes'  => 35,
				'color'    => array( 0x1D, 0x6F, 0xB8 ),
				'alt'      => array(
					'en' => 'Boats lined up along the harbor wall',
					'ku' => 'Qeyikên li kêleka dîwarê bendergehê rêz bûne',
					'ar' => 'قوارب مصطفة بجانب جدار الميناء',
				),
				'flags'    => array( 'ku' => 'empty_paragraph' ),
				'titles'   => array(
					'en' => 'Harbor festival returns this weekend with food, boats and live music',
					'ku' => 'Festîvala bendergehê vê dawiya hefteyê vedigere bi xwarin, qeyik û muzîka zindî',
					'ar' => 'مهرجان الميناء يعود نهاية هذا الأسبوع بالطعام والقوارب والموسيقى الحية',
				),
				'body'     => array(
					'en' => array(
						'The annual harbor festival returns on Saturday and Sunday, with events from morning until evening along the main quay.',
						'Local stalls will serve regional dishes, a fleet of restored boats will sail past the harbor wall, and a live concert starts every evening at seven.',
						'Entry is free. Organizers ask visitors to arrive early because nearby parking is limited, and to walk or take the bus where they can.',
					),
					'ku' => array(
						'Festîvala salane ya bendergehê roja şemiyê û yekşemiyê vedigere. Ew ji sibê heta êvarê li bendergehê berdewam dike.',
						'Kioskên herêmî xwarinên herêmî pêşkêş dikin, qeyikên kevn li ser avê têne nîşandan û her êvar konsertek muzîkê tê lidarxistin.',
						'Têketin belaş e. Organîzator ji mêvanan re şîret dikin ku zû werin, ji ber ku cihên parkê yên nêzîk kêm in. Heke gengaz be, bi peyatî an bi otobusê werin.',
					),
					'ar' => array(
						'يعود مهرجان الميناء السنوي يوم السبت والأحد، ويستمر من الصباح حتى المساء على الرصيف الرئيسي.',
						'ستقدّم أكشاك محلية أطباقًا من المطبخ المحلي، وستعرض القوارب القديمة على الماء، ويُقام عرض موسيقي كل مساء.',
						'الدخول مجاني. ينصح المنظمون الزوار بالقدوم مبكرًا لأن مواقف السيارات القريبة محدودة، وبالمشي أو استخدام الحافلة حيثما أمكن.',
					),
				),
			),
			'library'  => array(
				'category' => 'community',
				'minutes'  => 190,
				'color'    => array( 0x2E, 0x8B, 0x57 ),
				'alt'      => array(
					'en' => 'Shelves of books in a bright reading room',
					'ku' => 'Refên pirtûkan di odeyeke xwendinê ya ronî de',
					'ar' => 'رفوف كتب في قاعة قراءة مضيئة',
				),
				'flags'    => array( 'en' => 'alt_missing' ),
				'titles'   => array(
					'en' => 'New town library opens with a reading room for children',
					'ku' => 'Pirtûkxaneya nû ya bajêr bi odeyeke xwendinê ya zarokan vedibe',
					'ar' => 'افتتاح مكتبة جديدة للمدينة مع قاعة قراءة للأطفال',
				),
				'body'     => array(
					'en' => array(
						'The new public library opens its doors on Monday after more than two years of work. The building holds over ten thousand books and a quiet hall for reading.',
						'The first floor has a room for children, with picture books, low chairs and a story corner. A weekly reading hour for families takes place there.',
						'It is open from nine in the morning until six in the evening, Monday to Saturday. Membership is free for all residents.',
					),
					'ku' => array(
						'Pirtûkxaneya giştî ya nû roja duşemê deriyên xwe vedike, piştî zêdetirî du salan xebatê. Avahî zêdetirî deh hezar pirtûk û salonek aram ji bo xwendinê dihewîne.',
						'Li qata yekem odeyek ji bo zarokan hatiye amadekirin, bi pirtûkên bi wêne, kursiyên nizm û quncikeke çîrokan. Her hefte li wir bernameyeke xwendinê ji bo malbatan heye.',
						'Demên kar ji duşemê heta şemiyê ji saet 09:00 heta 18:00 ne. Endamtî ji bo hemû niştecihan belaş e.',
					),
					'ar' => array(
						'تفتح المكتبة العامة الجديدة أبوابها يوم الاثنين بعد أكثر من عامين من العمل. يضم المبنى أكثر من عشرة آلاف كتاب وقاعة هادئة للمطالعة.',
						'خُصصت في الطابق الأول قاعة للأطفال فيها كتب مصورة ومقاعد منخفضة وركن للحكايات. وتُقام فيها حصة قراءة أسبوعية للعائلات.',
						'ساعات العمل من التاسعة صباحًا حتى السادسة مساءً من الاثنين إلى السبت. الاشتراك مجاني لجميع السكان.',
					),
				),
			),
			'road'     => array(
				'category' => 'transport',
				'minutes'  => 520,
				'color'    => array( 0xC2, 0x6A, 0x1F ),
				'alt'      => array(
					'en' => 'A stone bridge with a barrier across the road',
					'ku' => 'Pirek a keviran bi bendereke li ser rê',
					'ar' => 'جسر حجري وحاجز على الطريق',
				),
				'flags'    => array( 'en' => 'title_long' ),
				'titles'   => array(
					'en' => 'The road over the old bridge closes for two weeks of repairs, with a signed detour through the town center',
					'ku' => 'Rê ber bi pira kevn ve ji bo du hefteyan ji ber tamîrê tê girtin',
					'ar' => 'إغلاق الطريق المؤدي إلى الجسر القديم لأسبوعين بسبب أعمال الصيانة',
				),
				'body'     => array(
					'en' => array(
						'The council will close the road over the old bridge to vehicles from next Sunday, so the surface can be repaired and the lighting replaced.',
						'A detour is signed through the town center. Journeys are expected to take about ten minutes longer at rush hour. The bridge stays open to walkers for the whole period.',
						'The council advises drivers to set out earlier and promises to post any change to the schedule as soon as it is known.',
					),
					'ku' => array(
						'Şaredarî dê rê ber bi pira kevn ve ji roja yekşema bê ve ji bo wesayîtan bigire, da ku rûyê pirê were tamîrkirin û ronahî were nûkirin.',
						'Rêyeke alternatîf bi nîşanan hatiye diyarkirin, û tê pêşbînîkirin ku rêwîtî di saetên qelebalixê de bi qasî deh deqeyan dirêj bibe. Pir ji bo peyayan di tevahiya kar de vekirî dimîne.',
						'Şaredarî ji ajokaran re şîret dike ku rêwîtiyên xwe zûtir dest pê bikin, û soz dide ku her guhertinê di bernameyê de yekser ragihîne.',
					),
					'ar' => array(
						'ستغلق البلدية الطريق المؤدي إلى الجسر القديم أمام السيارات ابتداءً من يوم الأحد المقبل، وذلك لإصلاح سطح الجسر وتجديد الإنارة.',
						'ستكون هناك طريق التفافية موضحة بالإشارات، ويُتوقع أن يزيد زمن الرحلة بنحو عشر دقائق في ساعات الذروة. يبقى الجسر مفتوحًا للمشاة طوال فترة العمل.',
						'تنصح البلدية السائقين بالتخطيط لرحلاتهم مسبقًا، وتعد بنشر أي تغيير في الجدول فور حدوثه.',
					),
				),
			),
			'ferry'    => array(
				'category' => 'transport',
				'minutes'  => 1350,
				'color'    => array( 0x6A, 0x4C, 0xA8 ),
				'alt'      => array(
					'en' => 'A small ferry crossing calm water',
					'ku' => 'Keştiyeke piçûk li ser avekî aram',
					'ar' => 'عبّارة صغيرة تعبر مياهًا هادئة',
				),
				'flags'    => array(),
				'titles'   => array(
					'en' => 'Ferry service to the island starts next week',
					'ku' => 'Karûbarê keştiya ber bi giravê ve hefteya bê dest pê dike',
					'ar' => 'بدء خدمة العبّارة إلى الجزيرة الأسبوع المقبل',
				),
				'body'     => array(
					'en' => array(
						'A daily ferry service to the island starts next Tuesday, with three round trips a day.',
						'The ferry carries one hundred passengers and a small number of vehicles. The crossing takes about forty minutes, and the first sailing leaves at eight in the morning.',
						'Tickets are sold at the harbor up to one hour before sailing. If the weather is bad, the changed timetable is posted on the board at the harbor.',
					),
					'ku' => array(
						'Karûbarê rojane yê keştiyê ber bi giravê ve roja sêşemê ya bê dest pê dike, û rojê sê seferên çûn û hatinê hene.',
						'Keştî dikare sed rêwiyan û çend wesayîtan hilgire. Rêwîtî nêzîkî çil deqeyan dikişîne û seferê yekem di saet 08:00 ê sibê de ye.',
						'Bilêt saetek beriya rêwîtiyê li bendergehê têne firotin. Dema hewa xirab be, bernameya guhertî li ser tabloya bendergehê tê ragihandin.',
					),
					'ar' => array(
						'تبدأ خدمة العبّارة اليومية إلى الجزيرة يوم الثلاثاء المقبل، وتسير ثلاث رحلات في اليوم ذهابًا وإيابًا.',
						'تتسع العبّارة لمئة راكب وعدد قليل من السيارات. تبلغ مدة الرحلة نحو أربعين دقيقة، وتنطلق أول رحلة في الثامنة صباحًا.',
						'يمكن شراء التذاكر في الميناء قبل الإبحار بساعة. وتُعلن مواعيد الرحلات المعدلة عند سوء الطقس على لوحة الميناء.',
					),
				),
			),
		);
	}

	/**
	 * The categories the samples use, by key.
	 *
	 * @return array<string, string> Key => name.
	 */
	public static function categories(): array {
		return array(
			'events'    => 'Sample: Events',
			'community' => 'Sample: Community',
			'transport' => 'Sample: Transport',
		);
	}
}
