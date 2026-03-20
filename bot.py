import asyncio
import aiohttp
from aiogram import Bot, Dispatcher, types, F
from aiogram.filters import Command
from aiogram.types import InlineKeyboardMarkup, InlineKeyboardButton
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import State, StatesGroup
from aiogram.fsm.storage.memory import MemoryStorage

BOT_TOKEN = "bottokencanim"
API_BASE = "https://arastir.sbs/api"

bot = Bot(token=BOT_TOKEN)
storage = MemoryStorage()
dp = Dispatcher(storage=storage)

class SorguStates(StatesGroup):
    tc_bekleniyor = State()
    adsoyad_ad = State()
    adsoyad_soyad = State()
    adsoyad_il = State()
    adsoyad_ilce = State()
    gsm_bekleniyor = State()

def ana_menu():
    keyboard = InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="🔍 TC Sorgula", callback_data="sorgu_tc")],
        [InlineKeyboardButton(text="👤 Ad Soyad Sorgula", callback_data="sorgu_adsoyad")],
        [InlineKeyboardButton(text="📱 TC'den GSM", callback_data="sorgu_tcgsm")],
        [InlineKeyboardButton(text="📞 GSM'den TC", callback_data="sorgu_gsmtc")],
        [InlineKeyboardButton(text="🏢 İşyeri Bilgisi", callback_data="sorgu_isyeri")],
        [InlineKeyboardButton(text="🏠 Adres Bilgisi", callback_data="sorgu_adres")],
        [InlineKeyboardButton(text="👨‍👩‍👧‍👦 Sulale Ağacı", callback_data="sorgu_sulale")],
        [InlineKeyboardButton(text="❓ Yardım", callback_data="yardim")]
    ])
    return keyboard

async def api_get(endpoint, params):
    try:
        async with aiohttp.ClientSession() as session:
            async with session.get(f"{API_BASE}/{endpoint}", params=params, timeout=30) as response:
                if response.status == 200:
                    return await response.json()
                return None
    except Exception as e:
        print(f"API hatası: {e}")
        return None

@dp.message(Command("start"))
async def cmd_start(message: types.Message):
    await message.answer(
        f"Merhaba {message.from_user.first_name}! 👋\n\n"
        "Ben bir sorgulama botuyum. Aşağıdaki butonlardan yapmak istediğiniz sorguyu seçebilirsiniz.\n\n"
        "💡 İpucu: /menu yazarak ana menüye dönebilirsiniz.",
        reply_markup=ana_menu()
    )

@dp.message(Command("menu"))
async def cmd_menu(message: types.Message):
    await message.answer("Ne yapmak istersiniz?", reply_markup=ana_menu())

@dp.callback_query(F.data == "yardim")
async def yardim_callback(callback: types.CallbackQuery):
    yardim_text = (
        "📖 Kullanım Kılavuzu\n\n"
        "🔍 TC Sorgula: TC kimlik no ile kişi bilgisi\n"
        "👤 Ad Soyad Sorgula: İsimle kişi arama\n"
        "📱 TC'den GSM: TC'ye kayıtlı telefonları göster\n"
        "📞 GSM'den TC: Telefon numarasının sahibi\n"
        "🏢 İşyeri Bilgisi: Çalışılan şirket bilgileri\n"
        "🏠 Adres Bilgisi: Kayıtlı adres bilgileri\n"
        "👨‍👩‍👧‍👦 Sulale Ağacı: Aile bireylerini göster\n\n"
        "Herhangi bir sorunuz varsa @destek yazabilirsiniz."
    )
    await callback.message.edit_text(yardim_text, parse_mode="HTML", reply_markup=ana_menu())
    await callback.answer()

@dp.callback_query(F.data == "sorgu_tc")
async def tc_sorgu_baslat(callback: types.CallbackQuery, state: FSMContext):
    await callback.message.edit_text("TC kimlik numarasını girin (11 haneli):")
    await state.set_state(SorguStates.tc_bekleniyor)
    await callback.answer()

@dp.message(SorguStates.tc_bekleniyor)
async def tc_sorgu_isle(message: types.Message, state: FSMContext):
    tc = message.text.strip()
    
    if not tc.isdigit() or len(tc) != 11:
        await message.answer("❌ Hatalı format! TC numarası 11 haneli olmalıdır.")
        return
    
    await message.answer("🔍 Sorgulanıyor, lütfen bekleyin...")
    
    sonuc = await api_get("tc.php", {"tc": tc})
    
    if sonuc and sonuc.get("success") == "true":
        cevap = (
            f"✅ Kişi Bilgileri\n\n"
            f"👤 Ad Soyad: {sonuc.get('ADI', '-')} {sonuc.get('SOYADI', '-')}\n"
            f"🆔 TC: {sonuc.get('TC', '-')}\n"
            f"🎂 Doğum Tarihi: {sonuc.get('DOGUMTARIHI', '-')}\n"
            f"📍 Nüfus: {sonuc.get('NUFUSIL', '-')} / {sonuc.get('NUFUSILCE', '-')}\n"
            f"👩 Anne Adı: {sonuc.get('ANNEADI', '-')} (TC: {sonuc.get('ANNETC', '-')})\n"
            f"👨 Baba Adı: {sonuc.get('BABAADI', '-')} (TC: {sonuc.get('BABATC', '-')})\n"
            f"🌍 Uyruk: {sonuc.get('UYRUK', '-')}"
        )
        await message.answer(cevap, parse_mode="HTML", reply_markup=ana_menu())
    else:
        await message.answer("❌ Kayıt bulunamadı.", reply_markup=ana_menu())
    
    await state.clear()

@dp.callback_query(F.data == "sorgu_adsoyad")
async def adsoyad_sorgu_baslat(callback: types.CallbackQuery, state: FSMContext):
    await callback.message.edit_text("Kişinin adını girin:")
    await state.set_state(SorguStates.adsoyad_ad)
    await callback.answer()

@dp.message(SorguStates.adsoyad_ad)
async def adsoyad_ad_al(message: types.Message, state: FSMContext):
    await state.update_data(adi=message.text.strip())
    await message.answer("Şimdi soyadını girin:")
    await state.set_state(SorguStates.adsoyad_soyad)

@dp.message(SorguStates.adsoyad_soyad)
async def adsoyad_soyad_al(message: types.Message, state: FSMContext):
    await state.update_data(soyadi=message.text.strip())
    
    keyboard = InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="✅ Direkt Sorgula", callback_data="adsoyad_direkt")],
        [InlineKeyboardButton(text="🗺 İl Ekle", callback_data="adsoyad_il_ekle")]
    ])
    
    await message.answer("Filtreleme yapmak ister misiniz?", reply_markup=keyboard)

@dp.callback_query(F.data == "adsoyad_direkt")
async def adsoyad_direkt_sorgu(callback: types.CallbackQuery, state: FSMContext):
    data = await state.get_data()
    await callback.message.edit_text("🔍 Sorgulanıyor...")
    
    sonuc = await api_get("adsoyad.php", {"adi": data['adi'], "soyadi": data['soyadi']})
    
    if sonuc and sonuc.get("success") == "true":
        kayitlar = sonuc.get("data", [])
        if len(kayitlar) > 10:
            cevap = f"✅ {len(kayitlar)} kayıt bulundu\n\nİlk 10 kayıt gösteriliyor:\n\n"
            kayitlar = kayitlar[:10]
        else:
            cevap = f"✅ {len(kayitlar)} kayıt bulundu:\n\n"
        
        for i, k in enumerate(kayitlar, 1):
            cevap += (
                f"{i}. {k.get('ADI', '-')} {k.get('SOYADI', '-')}\n"
                f"   TC: {k.get('TC', '-')}\n"
                f"   📍 {k.get('NUFUSIL', '-')} / {k.get('NUFUSILCE', '-')}\n\n"
            )
        
        await callback.message.edit_text(cevap, parse_mode="HTML", reply_markup=ana_menu())
    else:
        await callback.message.edit_text("❌ Kayıt bulunamadı.", reply_markup=ana_menu())
    
    await state.clear()
    await callback.answer()

@dp.callback_query(F.data == "adsoyad_il_ekle")
async def adsoyad_il_ekle(callback: types.CallbackQuery, state: FSMContext):
    await callback.message.edit_text("İl adını girin:")
    await state.set_state(SorguStates.adsoyad_il)
    await callback.answer()

@dp.message(SorguStates.adsoyad_il)
async def adsoyad_il_al(message: types.Message, state: FSMContext):
    await state.update_data(il=message.text.strip())
    
    keyboard = InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="✅ Sorgula", callback_data="adsoyad_il_sorgu")],
        [InlineKeyboardButton(text="🏘 İlçe Ekle", callback_data="adsoyad_ilce_ekle")]
    ])
    
    await message.answer("İlçe de eklemek ister misiniz?", reply_markup=keyboard)

@dp.callback_query(F.data == "adsoyad_ilce_ekle")
async def adsoyad_ilce_ekle(callback: types.CallbackQuery, state: FSMContext):
    await callback.message.edit_text("İlçe adını girin:")
    await state.set_state(SorguStates.adsoyad_ilce)
    await callback.answer()

@dp.message(SorguStates.adsoyad_ilce)
async def adsoyad_ilce_al(message: types.Message, state: FSMContext):
    await state.update_data(ilce=message.text.strip())
    data = await state.get_data()
    
    await message.answer("🔍 Sorgulanıyor...")
    
    params = {"adi": data['adi'], "soyadi": data['soyadi'], "il": data['il'], "ilce": data['ilce']}
    sonuc = await api_get("adsoyad.php", params)
    
    if sonuc and sonuc.get("success") == "true":
        kayitlar = sonuc.get("data", [])
        cevap = f"✅ {len(kayitlar)} kayıt bulundu:\n\n"
        
        for i, k in enumerate(kayitlar[:10], 1):
            cevap += (
                f"{i}. {k.get('ADI', '-')} {k.get('SOYADI', '-')}\n"
                f"   TC: {k.get('TC', '-')}\n"
                f"   📍 {k.get('NUFUSIL', '-')} / {k.get('NUFUSILCE', '-')}\n\n"
            )
        
        await message.answer(cevap, parse_mode="HTML", reply_markup=ana_menu())
    else:
        await message.answer("❌ Kayıt bulunamadı.", reply_markup=ana_menu())
    
    await state.clear()

@dp.callback_query(F.data == "adsoyad_il_sorgu")
async def adsoyad_il_sorgu(callback: types.CallbackQuery, state: FSMContext):
    data = await state.get_data()
    await callback.message.edit_text("🔍 Sorgulanıyor...")
    
    params = {"adi": data['adi'], "soyadi": data['soyadi'], "il": data['il']}
    sonuc = await api_get("adsoyad.php", params)
    
    if sonuc and sonuc.get("success") == "true":
        kayitlar = sonuc.get("data", [])
        cevap = f"✅ {len(kayitlar)} kayıt bulundu:\n\n"
        
        for i, k in enumerate(kayitlar[:10], 1):
            cevap += (
                f"{i}. {k.get('ADI', '-')} {k.get('SOYADI', '-')}\n"
                f"   TC: {k.get('TC', '-')}\n"
                f"   📍 {k.get('NUFUSIL', '-')} / {k.get('NUFUSILCE', '-')}\n\n"
            )
        
        await callback.message.edit_text(cevap, parse_mode="HTML", reply_markup=ana_menu())
    else:
        await callback.message.edit_text("❌ Kayıt bulunamadı.", reply_markup=ana_menu())
    
    await state.clear()
    await callback.answer()

@dp.callback_query(F.data == "sorgu_tcgsm")
async def tcgsm_sorgu(callback: types.CallbackQuery, state: FSMContext):
    await callback.message.edit_text("TC kimlik numarasını girin:")
    await state.set_state(SorguStates.tc_bekleniyor)
    await state.update_data(sorgu_tipi="tcgsm")
    await callback.answer()

@dp.callback_query(F.data == "sorgu_gsmtc")
async def gsmtc_sorgu_baslat(callback: types.CallbackQuery, state: FSMContext):
    await callback.message.edit_text("GSM numarasını girin (5551234567):")
    await state.set_state(SorguStates.gsm_bekleniyor)
    await callback.answer()

@dp.message(SorguStates.gsm_bekleniyor)
async def gsmtc_sorgu_isle(message: types.Message, state: FSMContext):
    gsm = message.text.strip()
    
    if not gsm.isdigit() or len(gsm) != 10:
        await message.answer("❌ Hatalı format! GSM 10 haneli olmalıdır (5551234567)")
        return
    
    await message.answer("🔍 Sorgulanıyor...")
    
    sonuc = await api_get("gsmtc.php", {"gsm": gsm})
    
    if sonuc and sonuc.get("success") == "true":
        cevap = (
            f"✅ Numara Sahibi\n\n"
            f"📱 GSM: {gsm}\n"
            f"👤 Ad Soyad: {sonuc.get('ADI', '-')} {sonuc.get('SOYADI', '-')}\n"
            f"🆔 TC: {sonuc.get('TC', '-')}"
        )
        await message.answer(cevap, parse_mode="HTML", reply_markup=ana_menu())
    else:
        await message.answer("❌ Kayıt bulunamadı.", reply_markup=ana_menu())
    
    await state.clear()

@dp.callback_query(F.data == "sorgu_isyeri")
async def isyeri_sorgu(callback: types.CallbackQuery, state: FSMContext):
    await callback.message.edit_text("TC kimlik numarasını girin:")
    await state.set_state(SorguStates.tc_bekleniyor)
    await state.update_data(sorgu_tipi="isyeri")
    await callback.answer()

@dp.callback_query(F.data == "sorgu_adres")
async def adres_sorgu(callback: types.CallbackQuery, state: FSMContext):
    await callback.message.edit_text("TC kimlik numarasını girin:")
    await state.set_state(SorguStates.tc_bekleniyor)
    await state.update_data(sorgu_tipi="adres")
    await callback.answer()

@dp.callback_query(F.data == "sorgu_sulale")
async def sulale_sorgu(callback: types.CallbackQuery, state: FSMContext):
    await callback.message.edit_text("TC kimlik numarasını girin:")
    await state.set_state(SorguStates.tc_bekleniyor)
    await state.update_data(sorgu_tipi="sulale")
    await callback.answer()

async def main():
    print("Bot başlatılıyor...")
    await dp.start_polling(bot)

if __name__ == "__main__":
    asyncio.run(main())