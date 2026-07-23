const SUPABASE_URL = process.env.SUPABASE_URL;
const SUPABASE_KEY = process.env.SUPABASE_ANON_KEY;

const headers = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Headers': 'Content-Type',
  'Content-Type': 'application/json'
};

exports.handler = async (event) => {
  if (event.httpMethod === 'OPTIONS') {
    return { statusCode: 204, headers };
  }
  if (event.httpMethod !== 'POST') {
    return { statusCode: 405, headers, body: JSON.stringify({ error: 'Method Not Allowed' }) };
  }

  try {
    const { zone_name, vote_type } = JSON.parse(event.body || '{}');
    if (!zone_name || !['famma', 'mafamech'].includes(vote_type)) {
      return { statusCode: 400, headers, body: JSON.stringify({ error: 'Paramètres invalides' }) };
    }

    const ip = (event.headers['x-forwarded-for'] || '').split(',')[0].trim()
      || event.headers['x-real-ip']
      || '0.0.0.0';

    // Check if already voted
    const checkRes = await fetch(
      `${SUPABASE_URL}/rest/v1/votes?zone_name=eq.${encodeURIComponent(zone_name)}&ip_address=eq.${encodeURIComponent(ip)}&select=vote_type`,
      { headers: { 'apikey': SUPABASE_KEY, 'Authorization': `Bearer ${SUPABASE_KEY}` } }
    );
    const existing = await checkRes.json();

    if (existing.length > 0) {
      return {
        statusCode: 200, headers,
        body: JSON.stringify({ success: false, already_voted: true, previous_vote: existing[0].vote_type })
      };
    }

    // Insert vote
    await fetch(`${SUPABASE_URL}/rest/v1/votes`, {
      method: 'POST',
      headers: {
        'apikey': SUPABASE_KEY,
        'Authorization': `Bearer ${SUPABASE_KEY}`,
        'Content-Type': 'application/json',
        'Prefer': 'return=minimal'
      },
      body: JSON.stringify({ zone_name, vote_type, ip_address: ip })
    });

    // Get zone counts
    const zoneRes = await fetch(
      `${SUPABASE_URL}/rest/v1/votes?zone_name=eq.${encodeURIComponent(zone_name)}&select=vote_type`,
      { headers: { 'apikey': SUPABASE_KEY, 'Authorization': `Bearer ${SUPABASE_KEY}` } }
    );
    const zoneVotes = await zoneRes.json();
    const zone_counts = { famma: 0, mafamech: 0 };
    zoneVotes.forEach(v => { zone_counts[v.vote_type] = (zone_counts[v.vote_type] || 0) + 1; });

    // Get global counts
    const globalRes = await fetch(
      `${SUPABASE_URL}/rest/v1/votes?select=vote_type`,
      { headers: { 'apikey': SUPABASE_KEY, 'Authorization': `Bearer ${SUPABASE_KEY}` } }
    );
    const allVotes = await globalRes.json();
    const global_counts = { famma: 0, mafamech: 0 };
    allVotes.forEach(v => { global_counts[v.vote_type] = (global_counts[v.vote_type] || 0) + 1; });

    return {
      statusCode: 200, headers,
      body: JSON.stringify({
        success: true,
        zone_counts,
        global_counts,
        message: vote_type === 'famma' ? '💧 Vote enregistré : Famma !' : '🚱 Vote enregistré : Mafamech !'
      })
    };
  } catch (err) {
    return { statusCode: 500, headers, body: JSON.stringify({ error: err.message }) };
  }
};
